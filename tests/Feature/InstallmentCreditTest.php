<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InstallmentCredit;
use App\Services\InvoicePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallmentCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_installment_credit', 'shop.paymongo.webhook_secret' => 'installment-credit-test-webhook']);
        Http::preventStrayRequests();
    }

    private function customer(?string $phone = '09123456789'): User
    {
        return User::factory()->create(['role' => 'customer', 'phone' => $phone]);
    }

    private function invoice(?User $customer, array $extra = []): Order
    {
        $phone = $extra['phone'] ?? $customer?->phone ?? '09123456789';

        return Order::create($extra + ['number' => 'CREDIT-'.Str::upper(Str::random(10)), 'user_id' => $customer?->id,
            'sales_channel' => $customer ? 'online' : 'walk_in', 'payment_plan' => 'partial', 'tier' => 'retail', 'quantity' => 2,
            'subtotal' => 120000, 'merchandise_base' => 120000, 'total' => 120000, 'amount_paid' => 25000,
            'payment_status' => 'partially_paid', 'recipient' => 'Credit Customer', 'phone' => $phone,
            'credit_phone_hash' => InstallmentCredit::phoneHash($phone), 'address' => 'Store pickup', 'delivery_method' => 'pickup',
            'due_at' => now()->addDays(7), 'original_due_at' => now()->addDays(7)]);
    }

    private function available(?User $customer, ?string $phone = '09123456789', ?int $except = null): void
    {
        DB::transaction(fn () => app(InstallmentCredit::class)->assertAvailable($customer?->id, $phone, $except));
    }

    private function assertBlocked(?User $customer, ?string $phone = '09123456789', ?int $except = null): void
    {
        try {
            $this->available($customer, $phone, $except);
            $this->fail('An existing partial invoice must prevent a second partial invoice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_option', $exception->errors());
            $this->assertStringContainsString('Settle the existing installment', $exception->errors()['payment_option'][0]);
        }
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Credit Test Tee', 'slug' => 'credit-test-'.Str::random(10), 'category' => '', 'description' => 'Credit fixture']);

        return $product->variants()->create(['sku' => 'CREDIT-'.Str::random(10), 'size' => 'M', 'color' => 'White', 'stock' => 30,
            'retail_price' => 60000, 'wholesale_price' => 55000, 'reseller_price' => 50000]);
    }

    private function checkout(array $extra = []): array
    {
        return $extra + ['recipient' => 'Credit Customer', 'phone' => '09123456789', 'delivery_method' => 'pickup',
            'payment_option' => 'partial', 'payment_amount' => '600.00', 'payment_days' => 7];
    }

    private function provider(): void
    {
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_credit_test',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/credit-test']]])]);
    }

    public static function openBalances(): array
    {
        return [['pending', 0], ['partially_paid', 25000]];
    }

    #[DataProvider('openBalances')]
    public function test_pending_and_partly_paid_installments_block_another_partial_order_for_the_same_customer(string $status, int $paid): void
    {
        $customer = $this->customer();
        $this->invoice($customer, ['payment_status' => $status, 'amount_paid' => $paid]);
        $this->assertBlocked($customer, '09987654321');
        Http::assertNothingSent();
    }

    public static function nonBlockingInvoices(): array
    {
        return [
            [['payment_status' => 'paid']],
            [['status' => 'cancelled']],
            [['inventory_released' => true]],
            [['payment_plan' => 'full']],
            [['amount_paid' => 120000]],
            [['amount_paid' => 120001]],
            [['payment_status' => 'failed']],
        ];
    }

    #[DataProvider('nonBlockingInvoices')]
    public function test_closed_released_full_and_zero_balance_invoices_do_not_use_the_partial_invoice_limit(array $state): void
    {
        $customer = $this->customer();
        $this->invoice($customer, $state);
        $this->available($customer);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    public static function phoneForms(): array
    {
        return [['09123456789'], ['639123456789'], ['+639123456789'], ['9123456789'], ['+63 (912) 345-6789']];
    }

    #[DataProvider('phoneForms')]
    public function test_guest_phone_formats_share_the_same_existing_guest_installment(string $phone): void
    {
        $this->invoice(null);
        $this->assertSame(InstallmentCredit::phoneHash('09123456789'), InstallmentCredit::phoneHash($phone));
        $this->assertBlocked(null, $phone);
        Http::assertNothingSent();
    }

    public function test_guest_cannot_bypass_registered_customer_debt_using_the_saved_phone_when_the_order_has_another_recipient_phone(): void
    {
        $customer = $this->customer('639123456789');
        $this->invoice($customer, ['phone' => '09987654321']);
        $this->assertBlocked(null, '+639123456789');
    }

    public function test_registered_customer_cannot_bypass_a_guest_installment_using_their_saved_phone(): void
    {
        $this->invoice(null);
        $customer = $this->customer('+639123456789');
        $this->assertBlocked($customer, '09987654321');
    }

    public function test_accounts_with_the_same_saved_phone_share_the_limit_even_with_different_recipient_phones(): void
    {
        $first = $this->customer('09123456789');
        $second = $this->customer('+639123456789');
        $this->invoice($first, ['phone' => '09987654321']);
        $this->assertBlocked($second, '09234567890');
    }

    public function test_an_order_phone_remains_bound_to_the_debt_after_the_registered_customer_changes_their_saved_phone(): void
    {
        $customer = $this->customer();
        $this->invoice($customer);
        $customer->update(['phone' => '09987654321']);
        $this->assertBlocked(null, '+639123456789');
        $this->assertBlocked($customer, '09234567890');
    }

    public function test_legacy_guest_partial_with_no_hash_is_still_found_by_canonical_phone_variants(): void
    {
        $this->invoice(null, ['credit_phone_hash' => null, 'phone' => '639123456789']);
        $this->assertBlocked(null, '09123456789');
    }

    public function test_different_customers_with_different_phones_can_each_have_an_installment(): void
    {
        $this->invoice($this->customer());
        $other = $this->customer('09987654321');
        $this->available($other, '+639987654321');
        $this->available(null, '09234567890');
        $this->assertDatabaseCount('orders', 1);
    }

    public static function invalidPhones(): array
    {
        return [[null], [''], ['123'], ['+12025550123'], ['091234567890'], ['09123456789<script>']];
    }

    #[DataProvider('invalidPhones')]
    public function test_guest_partial_requires_an_identifiable_valid_philippine_mobile_number(?string $phone): void
    {
        $this->assertNull(InstallmentCredit::phoneHash($phone));
        try {
            $this->available(null, $phone);
            $this->fail('A guest without a valid mobile number cannot open a partial invoice.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('valid Philippine mobile', $exception->errors()['payment_option'][0]);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('credit_locks', 0);
    }

    public function test_a_registered_customer_without_a_mobile_number_still_has_an_account_identity_lock(): void
    {
        $customer = $this->customer(null);
        $this->available($customer, null);
        $this->assertDatabaseCount('credit_locks', 1);
        $this->invoice($customer, ['phone' => '', 'credit_phone_hash' => null]);
        $this->assertBlocked($customer, null);
    }

    public function test_credit_locks_persist_only_hashes_and_require_an_existing_transaction(): void
    {
        $customer = $this->customer();
        $this->available($customer);
        $rows = DB::table('credit_locks')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['identity_hash'], array_keys((array) $row));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row->identity_hash);
        }
        DB::rollBack();
        try {
            app(InstallmentCredit::class)->assertAvailable(null, '09123456789');
            $this->fail('The identity lock cannot outlive its transaction.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('require a database transaction', $exception->getMessage());
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_second_customer_partial_checkout_creates_no_order_hold_or_provider_checkout(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer);
        $variant = $this->variant();
        $this->actingAs($customer)->withSession(['cart' => [$variant->id => 2], 'pending_order' => null])
            ->post('/checkout', $this->checkout())->assertSessionHasErrors('payment_option');
        $this->assertSame([$old->id], Order::pluck('id')->all());
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertSame(30, $variant->fresh()->stock);
        Http::assertNothingSent();
    }

    public function test_customer_with_an_existing_installment_can_place_a_new_full_payment_order(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer);
        $variant = $this->variant();
        $this->provider();
        $this->actingAs($customer)->withSession(['cart' => [$variant->id => 2], 'pending_order' => null])
            ->post('/checkout', $this->checkout(['payment_option' => 'full']))->assertSessionHasNoErrors()->assertRedirect('https://checkout.paymongo.com/credit-test');
        $new = Order::whereKeyNot($old->id)->sole();
        $this->assertSame('full', $new->payment_plan);
        $this->assertSame(120000, $new->payments()->sole()->amount);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
    }

    public function test_settling_the_existing_invoice_restores_partial_checkout_eligibility(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer);
        $old->update(['payment_status' => 'paid', 'amount_paid' => $old->total]);
        $variant = $this->variant();
        $this->provider();
        $this->actingAs($customer)->withSession(['cart' => [$variant->id => 2], 'pending_order' => null])
            ->post('/checkout', $this->checkout())->assertSessionHasNoErrors()->assertRedirect('https://checkout.paymongo.com/credit-test');
        $new = Order::whereKeyNot($old->id)->sole();
        $this->assertSame('partial', $new->payment_plan);
        $this->assertSame(InstallmentCredit::phoneHash('09123456789'), $new->credit_phone_hash);
        $this->assertSame(60000, $new->payments()->sole()->amount);
        Http::assertSentCount(1);
    }

    public function test_existing_invoice_topups_are_allowed_but_another_full_invoice_cannot_be_converted_to_partial(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->available($customer, $customer->phone, $old->id);
        $receipt = app(InvoicePayments::class)->recordCash($old, 10000, $staff, 'Cash payment counted and received.', (string) Str::uuid());
        $this->assertSame('paid', $receipt->status);
        $this->assertSame(35000, $old->fresh()->amount_paid);
        $other = $this->invoice($customer, ['payment_plan' => 'full', 'payment_status' => 'pending', 'amount_paid' => 0]);
        try {
            app(InvoicePayments::class)->start($other, 60000, $customer, (string) Str::uuid());
            $this->fail('A second invoice cannot be converted to partial while the first is open.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_option', $exception->errors());
        }
        $this->assertSame('full', $other->fresh()->payment_plan);
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_guest_walk_in_cannot_open_a_second_partial_invoice_but_can_pay_a_new_order_in_full(): void
    {
        $old = $this->invoice(null);
        $variant = $this->variant();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $payload = ['request_key' => (string) Str::uuid(), 'customer_type' => 'guest', 'recipient' => 'Walk-in Customer',
            'phone' => '+639123456789', 'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payment_option' => 'partial_cash', 'amount' => '600.00', 'due_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cash payment counted and received in store.', 'payment_received' => '1'];
        $this->post('/admin/walk-in', $payload)->assertSessionHasErrors('payment_option');
        $this->assertSame([$old->id], Order::pluck('id')->all());
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertSame(30, $variant->fresh()->stock);
        $this->post('/admin/walk-in', array_replace($payload, ['payment_option' => 'full_cash', 'request_key' => (string) Str::uuid()]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $new = Order::whereKeyNot($old->id)->sole();
        $this->assertNull($new->user_id);
        $this->assertSame('full', $new->payment_plan);
        $this->assertSame('paid', $new->payment_status);
        $this->assertSame('completed', $new->status);
        $this->assertSame(120000, $new->payments()->sole()->amount);
        $this->assertSame(28, $variant->fresh()->stock);
        Http::assertNothingSent();
    }

    public function test_online_conversion_of_an_existing_full_invoice_with_an_agreed_deadline_uses_the_credit_limit_immediately(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer, ['payment_plan' => 'full', 'payment_status' => 'pending', 'amount_paid' => 0]);
        $deadline = $old->getRawOriginal('due_at');
        $original = $old->getRawOriginal('original_due_at');
        $this->provider();
        $attempt = app(InvoicePayments::class)->start($old, 60000, $customer, (string) Str::uuid());
        $this->assertSame('pending', $attempt->status);
        $this->assertSame('partial', $old->fresh()->payment_plan);
        $this->assertSame($deadline, $old->fresh()->getRawOriginal('due_at'));
        $this->assertSame($original, $old->fresh()->getRawOriginal('original_due_at'));
        $this->assertBlocked($customer);
        Http::assertSentCount(1);
    }

    public function test_legacy_multiple_partial_invoices_do_not_prevent_a_customer_from_paying_toward_an_existing_invoice(): void
    {
        $customer = $this->customer();
        $first = $this->invoice($customer);
        $second = $this->invoice($customer);
        $receipt = app(InvoicePayments::class)->recordCash($first, 10000, User::factory()->create(['role' => 'staff']),
            'Cash payment received for the existing invoice.', (string) Str::uuid());
        $this->assertSame('paid', $receipt->status);
        $this->assertSame(35000, $first->fresh()->amount_paid);
        $this->assertSame(25000, $second->fresh()->amount_paid);
        $this->assertBlocked($customer);
        Http::assertNothingSent();
    }

    public function test_home_reminder_is_persistent_and_contains_only_the_signed_in_customers_open_balances(): void
    {
        $customer = $this->customer();
        $open = $this->invoice($customer, ['number' => 'VISIBLE-INSTALLMENT', 'due_at' => now()->subDay()]);
        $pending = $this->invoice($customer, ['number' => 'VISIBLE-FULL', 'payment_plan' => 'full', 'payment_status' => 'pending', 'amount_paid' => 0, 'due_at' => null]);
        $this->invoice($customer, ['number' => 'HIDDEN-CLOSED', 'payment_status' => 'paid']);
        $this->invoice($customer, ['number' => 'HIDDEN-CANCELLED', 'status' => 'cancelled']);
        $this->invoice($customer, ['number' => 'HIDDEN-RELEASED', 'inventory_released' => true]);
        $this->invoice($customer, ['number' => 'HIDDEN-NO-BALANCE', 'amount_paid' => 120000]);
        $this->invoice($this->customer(), ['number' => 'HIDDEN-OTHER-CUSTOMER']);
        $this->invoice(null, ['number' => 'HIDDEN-GUEST-SAME-PHONE']);
        $this->actingAs($customer);
        for ($visit = 0; $visit < 2; $visit++) {
            $this->get('/')->assertOk()->assertSee('id="payment-alert"', false)->assertSee('VISIBLE-INSTALLMENT')->assertSee('VISIBLE-FULL')
                ->assertSee('Remaining ₱950.00')->assertSee('Remaining ₱1,200.00')->assertSee('Overdue')
                ->assertSee(route('orders.show', $open))->assertSee(route('orders.show', $pending))
                ->assertDontSee('HIDDEN-CLOSED')->assertDontSee('HIDDEN-CANCELLED')->assertDontSee('HIDDEN-RELEASED')
                ->assertDontSee('HIDDEN-NO-BALANCE')->assertDontSee('HIDDEN-OTHER-CUSTOMER')->assertDontSee('HIDDEN-GUEST-SAME-PHONE');
        }
        Http::assertNothingSent();
    }

    public function test_login_reminder_also_appears_on_an_intended_account_redirect_and_refreshes_the_current_balance(): void
    {
        $customer = $this->customer();
        $customer->update(['password' => 'credit-test-password']);
        $open = $this->invoice($customer, ['number' => 'LOGIN-INSTALLMENT']);
        $this->withSession(['url.intended' => route('account')])->post('/login', ['email' => $customer->email, 'password' => 'credit-test-password'])
            ->assertRedirect(route('account'))->assertSessionHas('payment_alert', true);
        $open->update(['amount_paid' => 50000]);
        $this->get('/account')->assertOk()->assertSee('id="payment-alert"', false)->assertSee('LOGIN-INSTALLMENT')->assertSee('Remaining ₱700.00');
        $open->update(['payment_status' => 'paid', 'amount_paid' => 120000]);
        $this->get('/')->assertOk()->assertDontSee('id="payment-alert"', false);
    }

    public function test_reminder_is_absent_for_guests_staff_and_customers_without_open_balances_even_with_a_stale_flash_flag(): void
    {
        $this->invoice($this->customer(), ['number' => 'PRIVATE-INSTALLMENT']);
        $this->withSession(['payment_alert' => true])->get('/')->assertOk()->assertDontSee('id="payment-alert"', false)->assertDontSee('PRIVATE-INSTALLMENT');
        foreach (['staff', 'admin', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'phone' => '09123456789']))->withSession(['payment_alert' => true])
                ->get('/')->assertOk()->assertDontSee('id="payment-alert"', false)->assertDontSee('PRIVATE-INSTALLMENT');
        }
        Http::assertNothingSent();
    }
}
