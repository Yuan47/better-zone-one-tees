<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliveryRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_delivery', 'shop.lalamove.secret' => 'sk_test_delivery',
            'shop.lalamove.address' => '10 Store Street, Quezon City', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03',
            'shop.lalamove.phone' => '+639123456789', 'shop.lalamove.service' => 'MOTORCYCLE']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'staff'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function order(array $attributes = []): Order
    {
        return Order::create($attributes + ['number' => 'ZOT-DELIVERY-'.uniqid(), 'user_id' => $this->user('customer')->id,
            'tier' => 'retail', 'quantity' => 2, 'subtotal' => 30000, 'merchandise_base' => 30000, 'discount' => 0,
            'shipping' => 10000, 'total' => 40000, 'payment_status' => 'paid', 'status' => 'ready', 'inventory_committed' => true,
            'recipient' => 'Delivery Buyer', 'phone' => '09123456780', 'address' => '20 Buyer Street, Quezon City, Metro Manila, 1100, Philippines',
            'delivery_method' => 'lalamove', 'lat' => '14.676', 'lng' => '121.0437']);
    }

    private function uncertain(): Order
    {
        return app(DeliveryRecovery::class)->beginBooking($this->order());
    }

    private function attached(string $id = '107900701184'): Order
    {
        $order = $this->uncertain();
        app(DeliveryRecovery::class)->acceptBooking($order, ['orderId' => $id, 'status' => 'ASSIGNING_DRIVER', 'shareLink' => 'https://share.lalamove.com/test']);

        return $order->fresh();
    }

    private function provider(Order $order, string $status = 'ON_GOING', string $id = '107900701184'): array
    {
        $stops = $order->delivery_booking_context['stops'];
        // The provider normalizes a local Philippine number to its international form.
        $stops[1]['phone'] = '+639123456780';

        return ['orderId' => $id, 'status' => $status, 'shareLink' => 'https://share.lalamove.com/confirmed',
            'metadata' => ['order_number' => $order->number], 'stops' => $stops];
    }

    private function tracking(array $data): void
    {
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $data])]);
    }

    private function quotation(string $total = '100'): array
    {
        return ['quotationId' => 'quote_test', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
            'priceBreakdown' => ['currency' => 'PHP', 'total' => $total], 'stops' => [['stopId' => 'pickup'], ['stopId' => 'buyer']]];
    }

    public function test_recovery_endpoints_deny_guests_and_customers_before_provider_access(): void
    {
        $order = $this->uncertain();
        Http::fake();
        foreach (['refresh', 'recover'] as $operation) {
            $this->post('/admin/orders/'.$order->id.'/delivery/'.$operation, ['delivery_id' => '107900701184'])->assertRedirect('/login');
        }
        $this->actingAs($this->user('customer'));
        foreach (['refresh', 'recover'] as $operation) {
            $this->post('/admin/orders/'.$order->id.'/delivery/'.$operation, ['delivery_id' => '107900701184'])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public static function roles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_staff_and_admin_can_recover_a_verified_uncertain_delivery(string $role): void
    {
        $order = $this->uncertain();
        $this->tracking($this->provider($order));
        $this->actingAs($this->user($role))->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('out_for_delivery', $order->status);
        $this->assertSame('107900701184', $order->delivery_id);
        $this->assertSame('https://share.lalamove.com/confirmed', $order->tracking_url);
        $this->assertDatabaseHas('delivery_attempts', ['order_id' => $order->id, 'provider_delivery_id' => '107900701184', 'provider_status' => 'ON_GOING', 'cleared_at' => null]);
        $log = ActivityLog::sole();
        $this->assertSame('order.delivery_reconciled', $log->action);
        $this->assertSame(['previous_status' => 'delivery_booking', 'status' => 'out_for_delivery'], $log->metadata);
        $this->assertStringNotContainsString($order->address, $log->toJson());
        Http::assertSentCount(1);
    }

    public static function endedStatuses(): array
    {
        return [['CANCELED'], ['REJECTED'], ['EXPIRED']];
    }

    #[DataProvider('endedStatuses')]
    public function test_confirmed_ended_delivery_is_saved_and_can_be_rebooked_without_changing_money(string $status): void
    {
        $order = $this->attached();
        $this->tracking($this->provider($order, $status));
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('ready', $order->status);
        $this->assertNull($order->delivery_id);
        $this->assertNull($order->tracking_url);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(40000, $order->total);
        $this->assertTrue($order->inventory_committed);
        $this->assertDatabaseHas('delivery_attempts', ['provider_delivery_id' => '107900701184', 'provider_status' => $status]);
        $this->assertNotNull(DB::table('delivery_attempts')->sole()->cleared_at);
        Http::fake([
            '*/v3/quotations' => Http::response(['data' => $this->quotation('90')]),
            '*/v3/orders' => Http::response(['data' => ['orderId' => '107900701185', 'status' => 'ASSIGNING_DRIVER', 'shareLink' => 'https://share.lalamove.com/new']], 201),
        ]);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasNoErrors();
        $this->assertSame('107900701185', $order->fresh()->delivery_id);
        $this->assertDatabaseCount('delivery_attempts', 2);
        $this->assertDatabaseHas('delivery_attempts', ['provider_delivery_id' => '107900701184', 'provider_status' => $status]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/orders') && $request['data']['metadata']['order_number'] === $order->number);
        $this->assertSame(10000, $order->fresh()->shipping);
    }

    public function test_completed_provider_delivery_completes_order_and_repeated_status_check_is_not_an_action(): void
    {
        $order = $this->attached();
        $this->tracking($this->provider($order, 'COMPLETED'));
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('delivery_attempts', ['provider_status' => 'COMPLETED', 'cleared_at' => null]);
        $this->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public static function mismatches(): array
    {
        return [['metadata'], ['missing_metadata'], ['pickup'], ['address'], ['name'], ['phone'], ['coordinates'], ['stops'], ['id'], ['status']];
    }

    #[DataProvider('mismatches')]
    public function test_recovery_rejects_wrong_or_incomplete_provider_identity(string $mismatch): void
    {
        $order = $this->uncertain();
        $data = $this->provider($order);
        match ($mismatch) {
            'metadata' => $data['metadata']['order_number'] = 'ZOT-ANOTHER-ORDER',
            'missing_metadata' => $data['metadata'] = [],
            'pickup' => $data['stops'][0]['address'] = 'Different store',
            'address' => $data['stops'][1]['address'] = 'Different customer',
            'name' => $data['stops'][1]['name'] = 'Different Recipient',
            'phone' => $data['stops'][1]['phone'] = '+639999999999',
            'coordinates' => $data['stops'][1]['coordinates']['lat'] = '14.7',
            'stops' => $data['stops'] = [$data['stops'][0]],
            'id' => $data['orderId'] = '107900701199',
            'status' => $data['status'] = 'UNKNOWN',
        };
        $this->tracking($data);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_uses_saved_pickup_details_and_rejects_changed_provider_environment(): void
    {
        $order = $this->uncertain();
        $this->tracking($this->provider($order));
        config(['shop.lalamove.address' => 'Moved store', 'shop.lalamove.lat' => '15']);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])->assertSessionHasNoErrors();
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        config(['shop.demo' => false, 'shop.lalamove.mode' => 'production', 'shop.lalamove.key' => 'pk_prod_delivery', 'shop.lalamove.secret' => 'sk_prod_delivery']);
        $this->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasErrors('delivery');
        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }

    public function test_uncertain_booking_without_snapshot_cannot_adopt_an_id(): void
    {
        $order = $this->order(['status' => 'delivery_booking']);
        Http::fake();
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])->assertSessionHasErrors('delivery');
        Http::assertNothingSent();
        $this->assertSame('delivery_booking', $order->fresh()->status);
    }

    public function test_delivery_id_of_another_order_cannot_be_reused_even_with_matching_response_metadata(): void
    {
        $other = $this->attached();
        $order = $this->uncertain();
        $this->tracking($this->provider($order));
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertSame('107900701184', $other->fresh()->delivery_id);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public function test_closed_attempt_cannot_be_attached_again_during_a_new_booking(): void
    {
        $order = $this->attached();
        $this->tracking($this->provider($order, 'CANCELED'));
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasNoErrors();
        app(DeliveryRecovery::class)->beginBooking($order->fresh());
        $this->tracking($this->provider($order, 'ON_GOING'));
        $this->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => '107900701184'])->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public function test_provider_check_failure_keeps_state_and_does_not_expose_provider_error_text(): void
    {
        $order = $this->attached();
        Http::fake(['*/v3/orders/*' => Http::response(['message' => 'private-provider-secret'], 500)]);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasErrors('delivery');
        $this->assertStringNotContainsString('private-provider-secret', session('errors')->first('delivery'));
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertSame('107900701184', $order->fresh()->delivery_id);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_uncertain_booking_retry_does_not_send_a_second_provider_request(): void
    {
        $order = $this->order();
        Http::fake([
            '*/v3/quotations' => Http::response(['data' => $this->quotation()]),
            '*/v3/orders' => Http::failedConnection(),
        ]);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivery_booking_context);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_attached_active_delivery_cannot_be_booked_again(): void
    {
        $order = $this->attached();
        Http::fake();
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('107900701184', $order->fresh()->delivery_id);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_attached_delivery_can_be_checked_against_order_and_configured_pickup(): void
    {
        $order = $this->attached();
        $data = $this->provider($order, 'EXPIRED');
        $order->update(['delivery_booking_context' => null, 'status' => 'delivery_attention']);
        $this->tracking($data);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertDatabaseHas('delivery_attempts', ['provider_status' => 'EXPIRED']);
    }

    public function test_recovery_input_cannot_supply_an_arbitrary_provider_url_or_order_state(): void
    {
        $order = $this->uncertain();
        Http::fake();
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/recover', ['delivery_id' => 'https://attacker.example/foreign', 'status' => 'ready'])->assertSessionHasErrors('delivery_id');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        Http::assertNothingSent();
    }

    public function test_delivery_price_increase_does_not_book_or_change_paid_amount(): void
    {
        $order = $this->order();
        Http::fake(['*/v3/quotations' => Http::response(['data' => $this->quotation('125')])]);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertStringContainsString('higher', session('errors')->first('delivery'));
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertSame(10000, $order->fresh()->shipping);
        $this->assertSame(40000, $order->fresh()->total);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_malformed_booking_success_is_kept_uncertain_for_recovery(): void
    {
        $order = $this->order();
        Http::fake([
            '*/v3/quotations' => Http::response(['data' => $this->quotation()]),
            '*/v3/orders' => Http::response(['data' => ['orderId' => '107900701184']], 201),
        ]);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    public function test_status_response_cannot_overwrite_a_newer_delivery_booking(): void
    {
        $order = $this->attached();
        $data = $this->provider($order, 'CANCELED');
        Http::fake(function () use ($order, $data) {
            $order->update(['delivery_id' => '107900701185']);

            return Http::response(['data' => $data]);
        });
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery/refresh')->assertSessionHasErrors('delivery');
        $this->assertSame('107900701185', $order->fresh()->delivery_id);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertDatabaseHas('delivery_attempts', ['provider_delivery_id' => '107900701184', 'provider_status' => 'ASSIGNING_DRIVER', 'cleared_at' => null]);
    }

    public function test_failed_quotation_request_remains_ready_and_can_be_retried_safely(): void
    {
        $order = $this->order();
        Http::fakeSequence()->pushFailedConnection()
            ->push(['data' => $this->quotation()])
            ->push(['data' => ['orderId' => '107900701184', 'status' => 'ASSIGNING_DRIVER']], 201);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertStringContainsString('No delivery was booked', session('errors')->first('delivery'));
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_booking_context);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v3/orders'));
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasNoErrors();
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public static function invalidQuotations(): array
    {
        return [['id'], ['missing_id'], ['stops'], ['missing_stops'], ['duplicate_stops'], ['expiry'], ['missing_expiry'], ['expired'], ['price'], ['negative_price'], ['currency']];
    }

    #[DataProvider('invalidQuotations')]
    public function test_invalid_quotation_never_posts_a_delivery_and_keeps_order_ready(string $invalid): void
    {
        $order = $this->order();
        $quote = $this->quotation();
        match ($invalid) {
            'id' => $quote['quotationId'] = 'https://invalid.example/quote',
            'missing_id' => $quote['quotationId'] = null,
            'stops' => $quote['stops'][1]['stopId'] = '',
            'missing_stops' => $quote['stops'] = [],
            'duplicate_stops' => $quote['stops'][1]['stopId'] = 'pickup',
            'expiry' => $quote['expiresAt'] = 'tomorrow',
            'missing_expiry' => $quote['expiresAt'] = null,
            'expired' => $quote['expiresAt'] = now()->subMinute()->toIso8601String(),
            'price' => $quote['priceBreakdown']['total'] = '1e500',
            'negative_price' => $quote['priceBreakdown']['total'] = '-1',
            'currency' => $quote['priceBreakdown']['currency'] = 'HKD',
        };
        Http::fake(['*/v3/quotations' => Http::response(['data' => $quote])]);
        $this->actingAs($this->user())->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertSame(40000, $order->fresh()->total);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v3/orders'));
    }
}
