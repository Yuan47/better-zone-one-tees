<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_recovery',
            'shop.paymongo.webhook_secret' => 'recovery-webhook-secret']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function order(bool $known = true): Order
    {
        $product = Product::create(['name' => 'Recovery Tee', 'slug' => 'recovery-'.uniqid(), 'category' => '', 'description' => 'Test shirt']);
        $variant = $product->variants()->create(['sku' => 'RECOVERY-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
        $order = app(OrderFlow::class)->reserve([$variant->id => 6], ['recipient' => 'Recovery Buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $this->user()->id);
        if ($known) {
            $id = 'cs_recovery_'.$order->id;
            $order->update(['checkout_session_id' => $id, 'checkout_url' => 'https://checkout.paymongo.com/'.$id]);
        }

        return $order->fresh();
    }

    private function variant(Order $order): Variant
    {
        return $order->items()->firstOrFail()->variant;
    }

    private function payment(Order $order): array
    {
        return ['id' => 'pay_recovery_'.$order->id, 'type' => 'payment', 'attributes' => ['status' => 'paid',
            'currency' => 'PHP', 'amount' => $order->total, 'livemode' => false, 'paid_at' => now()->timestamp]];
    }

    private function checkoutSession(Order $order, string $status = 'active', array $payments = [], ?string $intent = 'awaiting_payment_method'): array
    {
        $id = $order->checkout_session_id ?? 'cs_recovered_'.$order->id;

        return ['id' => $id, 'type' => 'checkout_session', 'attributes' => [
            'reference_number' => $order->number, 'livemode' => false, 'status' => $status,
            'checkout_url' => 'https://checkout.paymongo.com/'.$id,
            'line_items' => [['name' => 'Clothing', 'currency' => 'PHP', 'amount' => $order->total, 'quantity' => 1]],
            'payments' => $payments, 'payment_intent' => $intent === null ? null : ['id' => 'pi_recovery_'.$order->id,
                'type' => 'payment_intent', 'attributes' => ['status' => $intent, 'amount' => $order->total, 'currency' => 'PHP', 'livemode' => false]],
        ]];
    }

    private function provider(array $session): void
    {
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/*' => Http::response(['data' => $session])]);
    }

    private function assertReserved(Order $order): void
    {
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->payment_status);
        $this->assertSame('awaiting_payment', $fresh->status);
        $this->assertFalse($fresh->inventory_committed);
        $this->assertFalse($fresh->inventory_released);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(20, $this->variant($order)->stock);
        $this->assertSame(6, $this->variant($order)->reserved);
    }

    public function test_missing_webhook_payment_is_confirmed_by_secret_authenticated_get_exactly_once(): void
    {
        $order = $this->order();
        $this->provider($this->checkoutSession($order, 'active', [$this->payment($order)], 'succeeded'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('packing', $fresh->status);
        $this->assertSame('pay_recovery_'.$order->id, $fresh->payment_id);
        $this->assertTrue($fresh->inventory_committed);
        $this->assertFalse($fresh->inventory_released);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(14, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        $this->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/payment/close')->assertSessionHasNoErrors();
        $this->assertSame(14, $this->variant($order)->stock);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/'.$order->checkout_session_id
            && $request->header('Authorization') === ['Basic '.base64_encode('sk_test_recovery:')]);
    }

    public function test_expire_unpaid_checkout_retrieves_final_state_and_releases_stock_once(): void
    {
        $order = $this->order();
        $active = $this->checkoutSession($order);
        $expired = $this->checkoutSession($order, 'expired');
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/*' => Http::sequence()
            ->push(['data' => $active])->push(['data' => $expired])->push(['data' => $expired])]);
        $this->actingAs($order->user)->withSession(['pending_order' => $order->id])
            ->post('/orders/'.$order->id.'/payment/close')->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('pending_order');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->inventory_released);
        $this->assertFalse($order->fresh()->inventory_committed);
        $this->assertNull($order->fresh()->checkout_url);
        $this->assertSame(20, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        $this->post('/orders/'.$order->id.'/payment/close')->assertSessionHasNoErrors();
        $this->assertSame(0, $this->variant($order)->reserved);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/'.$order->checkout_session_id.'/expire');
    }

    public function test_recovered_payment_keeps_provider_payment_date_in_manila_sales_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $order = $this->order();
        $payment = $this->payment($order);
        $payment['attributes']['paid_at'] = Carbon::parse('2026-09-30 23:55:00', 'Asia/Manila')->timestamp;
        $order->update(['created_at' => Carbon::parse('2026-09-30 23:50:00', 'Asia/Manila')]);
        $this->provider($this->checkoutSession($order, 'active', [$payment], 'succeeded'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->assertSame('2026-09-30 23:55:00', $order->fresh()->paid_at->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $chart = app(SalesReport::class)->chart('monthly');
        $this->assertSame($order->total, $chart['rows'][10]['amount']);
        $this->assertSame(0, $chart['rows'][11]['amount']);
        $this->travelBack();
    }

    public function test_missing_provider_payment_date_uses_confirmation_time_for_legacy_compatibility(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $order = $this->order();
        $payment = $this->payment($order);
        unset($payment['attributes']['paid_at']);
        $this->provider($this->checkoutSession($order, 'active', [$payment], 'succeeded'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-11 12:00:00', $order->fresh()->paid_at->format('Y-m-d H:i:s'));
        $this->travelBack();
    }

    public static function invalidPaymentDates(): array
    {
        return [['future'], ['zero'], ['negative'], ['string'], ['before_order']];
    }

    #[DataProvider('invalidPaymentDates')]
    public function test_invalid_provided_payment_date_refuses_confirmation(string $case): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $payment['attributes']['paid_at'] = match ($case) {
            'future' => now()->addSeconds(600)->timestamp,
            'zero' => 0,
            'negative' => -1,
            'string' => (string) now()->timestamp,
            'before_order' => 1,
        };
        $this->provider($this->checkoutSession($order, 'active', [$payment], 'succeeded'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasErrors('payment');
        $this->assertReserved($order);
    }

    public function test_signed_webhook_refuses_payment_date_before_the_order_existed(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $payment['attributes']['paid_at'] = 1;
        $body = json_encode(['data' => ['id' => 'evt_impossible_date', 'attributes' => [
            'type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => [
                'id' => $order->checkout_session_id, 'attributes' => ['reference_number' => $order->number, 'payments' => [$payment]],
            ],
        ]]]);
        $time = (string) time();
        $signature = hash_hmac('sha256', $time.'.'.$body, 'recovery-webhook-secret');
        $this->call('POST', '/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.$signature,
        ], $body)->assertStatus(409);
        $this->assertReserved($order);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_expired_never_started_v2_session_with_explicit_null_intent_is_safe_to_release(): void
    {
        $order = $this->order();
        $this->provider($this->checkoutSession($order, 'expired', [], null));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertTrue($order->fresh()->inventory_released);
        $this->assertSame(20, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        Http::assertSentCount(1);
    }

    public function test_paid_provider_result_after_expiration_confirms_payment_instead_of_releasing_stock(): void
    {
        $order = $this->order();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/*' => Http::sequence()
            ->push(['data' => $this->checkoutSession($order)])
            ->push(['data' => $this->checkoutSession($order, 'expired')])
            ->push(['data' => $this->checkoutSession($order, 'expired', [$this->payment($order)], 'succeeded')])]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/close')->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertFalse($order->fresh()->inventory_released);
        $this->assertSame(14, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        Http::assertSentCount(3);
    }

    public function test_webhook_committed_during_expiration_is_not_undone_by_stale_unpaid_provider_response(): void
    {
        $order = $this->order();
        Http::fake(function ($request) use ($order) {
            if ($request->method() === 'POST') {
                DB::transaction(function () use ($order) {
                    app(OrderFlow::class)->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_webhook_race');
                });

                return Http::response(['data' => $this->checkoutSession($order, 'expired')]);
            }

            return Http::response(['data' => $this->checkoutSession($order, $order->fresh()->payment_status === 'paid' ? 'expired' : 'active')]);
        });
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/close')->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('pay_webhook_race', $order->fresh()->payment_id);
        $this->assertFalse($order->fresh()->inventory_released);
        $this->assertSame(14, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        Http::assertSentCount(3);
    }

    public static function unavailableStages(): array
    {
        return [['retrieve_timeout'], ['expire_timeout'], ['final_retrieve_timeout']];
    }

    #[DataProvider('unavailableStages')]
    public function test_network_uncertainty_never_releases_or_commits_stock(string $stage): void
    {
        $order = $this->order();
        $gets = 0;
        Http::fake(function ($request) use ($order, $stage, &$gets) {
            if ($request->method() === 'GET') {
                $gets++;
            }
            if (($stage === 'retrieve_timeout' && $gets === 1) || ($stage === 'expire_timeout' && $request->method() === 'POST')
                || ($stage === 'final_retrieve_timeout' && $gets === 2)) {
                throw new ConnectionException('Simulated provider timeout');
            }

            return Http::response(['data' => $this->checkoutSession($order)]);
        });
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/close')->assertSessionHasErrors('payment');
        $this->assertReserved($order);
        $this->assertNotNull($order->fresh()->checkout_url);
    }

    public static function invalidSessions(): array
    {
        return array_map(fn ($case) => [$case], ['session_id', 'reference', 'session_mode', 'line_amount', 'line_currency',
            'line_quantity', 'missing_lines', 'missing_payments', 'payment_amount', 'payment_currency', 'payment_mode', 'payment_id',
            'processing_intent', 'missing_intent', 'pending_payment', 'missing_payment_status']);
    }

    #[DataProvider('invalidSessions')]
    public function test_unverified_or_unsettled_payment_details_leave_reservation_untouched(string $case): void
    {
        $order = $this->order();
        $paidCase = str_starts_with($case, 'payment_');
        $session = $this->checkoutSession($order, 'expired', $paidCase ? [$this->payment($order)] : [], $paidCase ? 'succeeded' : 'awaiting_payment_method');
        switch ($case) {
            case 'session_id': $session['id'] = 'cs_another';
                break;
            case 'reference': $session['attributes']['reference_number'] = 'ANOTHER-ORDER';
                break;
            case 'session_mode': $session['attributes']['livemode'] = true;
                break;
            case 'line_amount': $session['attributes']['line_items'][0]['amount']--;
                break;
            case 'line_currency': $session['attributes']['line_items'][0]['currency'] = 'USD';
                break;
            case 'line_quantity': $session['attributes']['line_items'][0]['quantity'] = 0;
                break;
            case 'missing_lines': unset($session['attributes']['line_items']);
                break;
            case 'missing_payments': unset($session['attributes']['payments']);
                break;
            case 'payment_amount': $session['attributes']['payments'][0]['attributes']['amount']--;
                break;
            case 'payment_currency': $session['attributes']['payments'][0]['attributes']['currency'] = 'USD';
                break;
            case 'payment_mode': $session['attributes']['payments'][0]['attributes']['livemode'] = true;
                break;
            case 'payment_id': $session['attributes']['payments'][0]['id'] = 'not_a_payment';
                break;
            case 'processing_intent': $session['attributes']['payment_intent']['attributes']['status'] = 'processing';
                break;
            case 'missing_intent': unset($session['attributes']['payment_intent']);
                break;
            case 'pending_payment': $session['attributes']['payments'] = [['id' => 'pay_pending', 'attributes' => ['status' => 'pending']]];
                break;
            case 'missing_payment_status': $session['attributes']['payments'] = [['id' => 'pay_unknown', 'attributes' => []]];
                break;
        }
        $this->provider($session);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasErrors('payment');
        $this->assertReserved($order);
        Http::assertSentCount(1);
    }

    public function test_active_unpaid_checkout_is_pending_and_not_recreated(): void
    {
        $order = $this->order();
        $this->provider($this->checkoutSession($order, 'active', [], 'processing'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->assertReserved($order);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_guests_and_other_customers_cannot_check_or_close_orders(): void
    {
        $order = $this->order();
        Http::fake();
        $this->post('/orders/'.$order->id.'/payment/check')->assertRedirect(route('login'));
        $this->post('/orders/'.$order->id.'/payment/close')->assertRedirect(route('login'));
        $this->actingAs($this->user())->post('/orders/'.$order->id.'/payment/check')->assertForbidden();
        $this->post('/orders/'.$order->id.'/payment/close')->assertForbidden();
        $this->assertReserved($order);
        Http::assertNothingSent();
    }

    public static function storeRoles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('storeRoles')]
    public function test_staff_and_owner_can_check_customer_payment_status(string $role): void
    {
        $order = $this->order();
        $this->provider($this->checkoutSession($order));
        $this->actingAs($this->user($role))->post('/orders/'.$order->id.'/payment/check')->assertSessionHasNoErrors();
        $this->assertReserved($order);
        Http::assertSentCount(1);
    }

    public function test_unknown_reference_check_is_blocked_until_owner_recovers_it(): void
    {
        $order = $this->order(false);
        Http::fake();
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasErrors('payment');
        $this->post('/orders/'.$order->id.'/payment/close')->assertSessionHasErrors('payment');
        $this->assertReserved($order);
        $this->assertNull($order->fresh()->checkout_session_id);
        Http::assertNothingSent();
    }

    public function test_only_owner_can_adopt_unknown_reference_and_confirm_a_verified_payment(): void
    {
        $order = $this->order(false);
        $session = $this->checkoutSession($order, 'active', [$this->payment($order)], 'succeeded');
        $this->provider($session);
        $url = '/admin/orders/'.$order->id.'/payment/recover';
        $this->post($url, ['checkout_session_id' => $session['id']])->assertRedirect(route('login'));
        $this->actingAs($order->user)->post($url, ['checkout_session_id' => $session['id']])->assertForbidden();
        $this->actingAs($this->user('staff'))->post($url, ['checkout_session_id' => $session['id']])->assertForbidden();
        Http::assertNothingSent();
        $this->actingAs($this->user('admin'))->post($url, ['checkout_session_id' => $session['id']])->assertSessionHasNoErrors();
        $this->assertSame($session['id'], $order->fresh()->checkout_session_id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(14, $this->variant($order)->stock);
        $this->assertSame(0, $this->variant($order)->reserved);
        Http::assertSentCount(1);
    }

    public function test_owner_cannot_adopt_reference_from_another_order_or_overwrite_an_existing_reference(): void
    {
        $order = $this->order(false);
        $other = $this->order();
        $this->actingAs($this->user('admin'));
        $this->provider($this->checkoutSession($other));
        $this->post('/admin/orders/'.$order->id.'/payment/recover', ['checkout_session_id' => $other->checkout_session_id])->assertSessionHasErrors('payment');
        $this->assertNull($order->fresh()->checkout_session_id);
        $this->assertReserved($order);

        // Even an inconsistent provider reference cannot steal a session already attached locally.
        $session = $this->checkoutSession($order);
        $session['id'] = $other->checkout_session_id;
        $this->provider($session);
        $this->post('/admin/orders/'.$order->id.'/payment/recover', ['checkout_session_id' => $session['id']])->assertSessionHasErrors('payment');
        $this->assertNull($order->fresh()->checkout_session_id);
        $this->assertSame($session['id'], $other->fresh()->checkout_session_id);
        $this->assertReserved($other);

        $this->provider($this->checkoutSession($other));
        $this->post('/admin/orders/'.$other->id.'/payment/recover', ['checkout_session_id' => $other->checkout_session_id])->assertSessionHasErrors('payment');
        $this->assertReserved($other);
    }

    public function test_owner_recovery_rejects_invalid_identifier_before_provider_access(): void
    {
        $order = $this->order(false);
        Http::fake();
        $this->actingAs($this->user('admin'))->post('/admin/orders/'.$order->id.'/payment/recover', ['checkout_session_id' => 'https://example.test/session'])
            ->assertSessionHasErrors('checkout_session_id');
        $this->assertNull($order->fresh()->checkout_session_id);
        $this->assertReserved($order);
        Http::assertNothingSent();
    }

    public function test_local_reservation_mismatch_rolls_back_expired_checkout_release(): void
    {
        $order = $this->order();
        $this->variant($order)->update(['reserved' => 5]);
        $this->provider($this->checkoutSession($order, 'expired'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payment/check')->assertSessionHasErrors('payment');
        $this->assertFalse($order->fresh()->inventory_released);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertSame(5, $this->variant($order)->reserved);
        $this->assertSame(20, $this->variant($order)->stock);
    }
}
