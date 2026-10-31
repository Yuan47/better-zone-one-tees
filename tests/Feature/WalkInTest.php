<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalkInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_walk_in', 'shop.paymongo.webhook_secret' => 'walk-in-webhook-test']);
        Http::preventStrayRequests();
    }

    private function variant(int $stock = 500): Variant
    {
        $product = Product::create(['name' => 'Walk-in Tee', 'slug' => 'walk-in-'.uniqid(), 'category' => '', 'description' => 'Walk-in fixture']);

        return $product->variants()->create(['sku' => 'WALK-IN-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => $stock,
            'retail_price' => 60000, 'wholesale_price' => 55000, 'reseller_price' => 50000]);
    }

    private function staff(string $role = 'staff'): User
    {
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);

        return $actor;
    }

    private function payload(Variant $variant, string $option = 'full_cash', array $extra = []): array
    {
        return $extra + ['request_key' => (string) Str::uuid(), 'customer_type' => 'guest', 'recipient' => 'Walk-in Guest',
            'phone' => '09123456789', 'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payment_option' => $option, 'amount' => '700.50', 'due_at' => now()->addDays(14)->toDateString(),
            'reason' => 'Cash was counted and received at the store.', 'payment_received' => '1'];
    }

    private function provider(): void
    {
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_walk_in_test',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/walk-in-test']]])]);
    }

    public function test_guests_and_customer_accounts_cannot_open_or_submit_walk_in_checkout(): void
    {
        $variant = $this->variant();
        $this->get('/admin/walk-in')->assertRedirect('/admin/login');
        $this->post('/admin/walk-in', $this->payload($variant))->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/admin/walk-in')->assertForbidden();
        $this->post('/admin/walk-in', $this->payload($variant))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public static function storeRoles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('storeRoles')]
    public function test_staff_and_admin_form_only_lists_registered_customers_and_active_products(string $role): void
    {
        $variant = $this->variant();
        $hidden = $this->variant();
        $hidden->product->update(['active' => false]);
        $customer = User::factory()->create(['role' => 'customer']);
        $actor = $this->staff($role);
        $this->get('/admin/walk-in')->assertOk()->assertSee('Walk-in checkout')->assertSee('I confirm the cash payment was received in store.')
            ->assertViewHas('customers', fn ($customers) => $customers->modelKeys() === [$customer->id])
            ->assertViewHas('variants', fn ($variants) => $variants->pluck('id')->all() === [$variant->id]);
        $this->assertNotSame($actor->id, $customer->id);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_full_guest_cash_sale_uses_server_prices_without_creating_a_customer_or_changing_storefront_cart(): void
    {
        $variant = $this->variant();
        $actor = $this->staff();
        $cart = [$variant->id => 9];
        $payload = $this->payload($variant, 'full_cash', ['phone' => null, 'total' => 1, 'subtotal' => 1, 'shipping' => 99999,
            'unit_price' => 1, 'payment_status' => 'paid', 'customer_confirmed_at' => now()->toDateTimeString(), 'created_by' => 99999,
            'lines' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 1, 'total' => 2]]]);
        $this->withSession(['cart' => $cart])->post('/admin/walk-in', $payload)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('cart', $cart);
        $order = Order::sole();
        $this->assertNull($order->user_id);
        $this->assertSame($actor->id, $order->created_by);
        $this->assertSame('walk_in', $order->sales_channel);
        $this->assertSame('pickup', $order->delivery_method);
        $this->assertSame('Store pickup', $order->address);
        $this->assertSame(0, $order->shipping);
        $this->assertSame(120000, $order->total);
        $this->assertSame(60000, $order->items()->sole()->unit_price);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(120000, $order->paidAmount());
        $this->assertSame(0, $order->outstandingBalance());
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->walk_in_received_at);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(498, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        $receipt = PaymentAttempt::sole();
        $this->assertSame('cash', $receipt->method);
        $this->assertSame('paid', $receipt->status);
        $this->assertSame(120000, $receipt->amount);
        $this->assertSame($actor->id, $receipt->recorded_by);
        Http::assertNothingSent();
    }

    public function test_registered_customer_sees_the_walk_in_sale_in_own_history_and_other_customer_cannot_open_it(): void
    {
        $variant = $this->variant();
        $customer = User::factory()->create(['role' => 'customer', 'phone' => '09123456789']);
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'full_cash', ['customer_type' => 'registered', 'customer_id' => $customer->id,
            'recipient' => 'Forged account name', 'phone' => null]))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame($customer->name, $order->recipient);
        $this->assertSame($customer->phone, $order->phone);
        $this->actingAs($customer)->get('/orders')->assertOk()->assertSee($order->number);
        $this->get('/orders/'.$order->id)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/orders/'.$order->id)->assertForbidden();
        Http::assertNothingSent();
    }

    #[DataProvider('storeRoles')]
    public function test_registered_customer_selection_cannot_link_a_store_account(string $role): void
    {
        $variant = $this->variant();
        $notCustomer = User::factory()->create(['role' => $role]);
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'full_cash', ['customer_type' => 'registered', 'customer_id' => $notCustomer->id]))
            ->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_creation_key_returns_existing_invoice_without_another_cash_receipt_or_stock_deduction(): void
    {
        $variant = $this->variant();
        $this->staff();
        $payload = $this->payload($variant);
        $this->post('/admin/walk-in', $payload)->assertSessionHasNoErrors();
        $order = Order::sole();
        $snapshot = $order->getAttributes();
        $this->post('/admin/walk-in', ['request_key' => $payload['request_key']])->assertRedirect('/orders/'.$order->id)->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(498, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_duplicate_variant_lines_merge_before_quantity_pricing_and_cash_collection(): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'full_cash', ['lines' => [
            ['variant_id' => $variant->id, 'quantity' => 3], ['variant_id' => $variant->id, 'quantity' => 3],
        ]]))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('wholesale', $order->tier);
        $this->assertSame(6, $order->quantity);
        $this->assertSame(330000, $order->total);
        $this->assertSame(6, $order->items()->sole()->quantity);
        $this->assertSame(330000, PaymentAttempt::sole()->amount);
        $this->assertSame(494, $variant->fresh()->stock);
    }

    public function test_partial_cash_keeps_invoice_total_and_original_due_date_and_does_not_close_sale(): void
    {
        $variant = $this->variant();
        $this->staff();
        $due = now()->addDays(14)->toDateString();
        $this->post('/admin/walk-in', $this->payload($variant, 'partial_cash', ['due_at' => $due]))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('partial', $order->payment_plan);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame(120000, $order->total);
        $this->assertSame(70050, $order->paidAmount());
        $this->assertSame(49950, $order->outstandingBalance());
        $this->assertSame($due, $order->due_at->toDateString());
        $this->assertSame('23:59:59', $order->due_at->format('H:i:s'));
        $this->assertSame($order->getRawOriginal('due_at'), $order->getRawOriginal('original_due_at'));
        $this->assertNotSame('completed', $order->status);
        $this->assertNull($order->walk_in_received_at);
        $this->assertFalse($order->inventory_committed);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertSame(70050, PaymentAttempt::sole()->amount);
        Http::assertNothingSent();
    }

    public static function onlineOptions(): array
    {
        return [['full_paymongo', 120000], ['partial_paymongo', 70050]];
    }

    public static function onlinePaymentModes(): array
    {
        return [['full_paymongo'], ['partial_paymongo']];
    }

    #[DataProvider('onlineOptions')]
    public function test_online_checkout_collects_only_selected_amount_and_never_confirms_a_redirect_as_payment(string $option, int $amount): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->provider();
        $payload = $this->payload($variant, $option);
        $this->post('/admin/walk-in', $payload)->assertRedirect('https://checkout.paymongo.com/walk-in-test')->assertSessionHasNoErrors();
        $order = Order::sole();
        $attempt = PaymentAttempt::sole();
        $this->assertSame(120000, $order->total);
        $this->assertSame(0, $order->paidAmount());
        $this->assertSame(120000, $order->outstandingBalance());
        $this->assertSame($amount, $attempt->amount);
        $this->assertSame('paymongo', $attempt->method);
        $this->assertSame('pending', $attempt->status);
        $this->assertNull($order->walk_in_received_at);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertDatabaseCount('users', 1);
        $this->post('/admin/walk-in', ['request_key' => $payload['request_key']])->assertRedirect('/orders/'.$order->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => array_sum(array_map(fn ($line) => $line['amount'] * $line['quantity'], $request['data']['attributes']['line_items'])) === $amount);
    }

    #[DataProvider('onlinePaymentModes')]
    public function test_unconfigured_online_checkout_is_blocked_before_creating_invoice_or_reserving_stock(string $option): void
    {
        $variant = $this->variant();
        $this->staff();
        config(['shop.paymongo.secret' => null]);
        $this->post('/admin/walk-in', $this->payload($variant, $option))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_uncertain_online_creation_keeps_one_invoice_and_replay_does_not_start_another_provider_request(): void
    {
        $variant = $this->variant();
        $this->staff();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::failedConnection()]);
        $payload = $this->payload($variant, 'full_paymongo');
        $this->post('/admin/walk-in', $payload)->assertSessionHasErrors('payment');
        $order = Order::sole();
        $this->post('/admin/walk-in', ['request_key' => $payload['request_key']])->assertRedirect('/orders/'.$order->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame('unknown', PaymentAttempt::sole()->status);
        $this->assertSame(0, $order->fresh()->paidAmount());
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
    }

    public static function cashOptions(): array
    {
        return [['full_cash'], ['partial_cash']];
    }

    #[DataProvider('cashOptions')]
    public function test_cash_needs_an_explicit_received_confirmation_and_staff_reason(string $option): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, $option, ['payment_received' => '0']))->assertSessionHasErrors('payment_received');
        $missingConfirmation = $this->payload($variant, $option);
        unset($missingConfirmation['payment_received']);
        $this->post('/admin/walk-in', $missingConfirmation)->assertSessionHasErrors('payment_received');
        $this->post('/admin/walk-in', $this->payload($variant, $option, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public static function invalidPartialDueDates(): array
    {
        return [[6], [31]];
    }

    public static function allowedPartialDueDates(): array
    {
        return [[7], [30]];
    }

    #[DataProvider('allowedPartialDueDates')]
    public function test_partial_due_date_includes_both_calendar_boundaries_and_is_saved_at_day_end(int $days): void
    {
        $variant = $this->variant();
        $this->staff();
        $due = now()->addDays($days)->toDateString();
        $this->post('/admin/walk-in', $this->payload($variant, 'partial_cash', ['due_at' => $due]))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame($due, $order->due_at->toDateString());
        $this->assertSame('23:59:59', $order->due_at->format('H:i:s'));
        $this->assertSame($order->getRawOriginal('due_at'), $order->getRawOriginal('original_due_at'));
    }

    #[DataProvider('invalidPartialDueDates')]
    public function test_partial_invoice_due_date_stays_within_seven_to_thirty_days(int $days): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'partial_cash', ['due_at' => now()->addDays($days)->toDateString()]))
            ->assertSessionHasErrors('due_at');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    #[DataProvider('onlinePaymentModes')]
    public function test_online_walk_in_needs_a_valid_phone_before_reservation(string $option): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, $option, ['phone' => null]))->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public static function partialOptions(): array
    {
        return [['partial_cash'], ['partial_paymongo']];
    }

    #[DataProvider('partialOptions')]
    public function test_ineligible_partial_invoice_rolls_back_items_and_stock_before_any_receipt_or_provider_request(string $option): void
    {
        $variant = $this->variant();
        $variant->update(['retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, $option))->assertSessionHasErrors();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_partial_payment_cannot_equal_or_exceed_the_invoice_total_and_rejected_creation_is_atomic(): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'partial_cash', ['amount' => '1200.00']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    public function test_insufficient_or_inactive_stock_creates_no_invoice_or_cash_receipt(): void
    {
        $variant = $this->variant(1);
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant))->assertSessionHasErrors('cart');
        $variant->update(['stock' => 20]);
        $variant->product->update(['active' => false]);
        $this->post('/admin/walk-in', $this->payload($variant))->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_cash_audit_failure_rolls_back_the_whole_new_invoice_receipt_and_stock_change(): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->mock(ActivityRecorder::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturnNull());
        try {
            $this->withoutExceptionHandling()->post('/admin/walk-in', $this->payload($variant));
            $this->fail('A cash receipt must not be saved without its required audit record.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('audited', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_creation_key_must_be_a_valid_uuid_before_invoice_or_receipt_work(): void
    {
        $variant = $this->variant();
        $this->staff();
        $this->post('/admin/walk-in', $this->payload($variant, 'full_cash', ['request_key' => 'not-a-uuid']))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }
}
