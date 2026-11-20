<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\DeliveryRecovery;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReceiptDeliveryVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_ID = '107900701184';
    private const CHECK_FAILED = 'We could not verify courier completion. No receipt was saved. Check the latest delivery status and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_receipt', 'shop.lalamove.secret' => 'sk_test_receipt',
            'shop.lalamove.address' => '10 Store Street, Quezon City', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03',
            'shop.lalamove.phone' => '+639123456789', 'shop.lalamove.service' => 'MOTORCYCLE']);
        Http::preventStrayRequests();
    }

    private function order(): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Receipt Verification Tee', 'slug' => 'receipt-verification-'.Str::uuid(), 'category' => '', 'description' => 'Test clothing', 'active' => true]);
        $variant = $product->variants()->create(['sku' => 'RV-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 10,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Receipt Customer', 'phone' => '09123456780',
            'address' => '20 Buyer Street, Quezon City, Metro Manila, 1100, Philippines', 'delivery_method' => 'lalamove',
            'lat' => '14.676', 'lng' => '121.0437', 'shipping' => 10000], $customer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_receipt_verification_'.$order->id));
        $order->refresh()->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'paid',
            'amount' => $order->total, 'payment_id' => $order->payment_id, 'received_at' => $order->paid_at]);
        $order->update(['status' => 'ready']);
        $claimed = app(DeliveryRecovery::class)->beginBooking($order->fresh());
        app(DeliveryRecovery::class)->acceptBooking($claimed, ['orderId' => self::PROVIDER_ID, 'status' => 'ASSIGNING_DRIVER']);

        return $order->fresh();
    }

    private function provider(Order $order, string $status = 'COMPLETED'): array
    {
        $stops = $order->delivery_booking_context['stops'];
        $stops[1]['phone'] = '+639123456780';

        return ['orderId' => self::PROVIDER_ID, 'status' => $status,
            'metadata' => ['order_number' => $order->number], 'stops' => $stops];
    }

    private function receipt(array $changes = []): array
    {
        return $changes + ['received' => '1', 'rating' => 5];
    }

    private function financialState(Order $order): array
    {
        return [
            'invoice' => array_intersect_key($order->fresh()->getRawOriginal(), array_flip(['user_id', 'quantity', 'tier',
                'subtotal', 'merchandise_base', 'discount', 'shipping', 'total', 'amount_paid', 'payment_plan', 'payment_status',
                'payment_id', 'paid_at', 'inventory_committed', 'inventory_released'])),
            'stock' => Variant::orderBy('id')->get()->map(fn ($variant) => $variant->only(['id', 'stock', 'reserved']))->all(),
            'payments' => DB::table('payment_attempts')->orderBy('id')->get()->toJson(),
            'items' => DB::table('order_items')->orderBy('id')->get()->toJson(),
        ];
    }

    private function assertReceiptNotSaved(Order $order, array $financial): void
    {
        $this->assertNull($order->fresh()->customer_confirmed_at);
        $this->assertNull($order->fresh()->provider_completed_at);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertSame(0, ActivityLog::where('action', 'order.received')->count());
        $this->assertSame($financial, $this->financialState($order));
    }

    public function test_customer_submission_verifies_stale_delivery_outside_receipt_transaction_and_saves_stars_once(): void
    {
        $order = $this->order();
        $financial = $this->financialState($order);
        $transactionLevel = DB::transactionLevel();
        Http::fake(function ($request) use ($order, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://rest.sandbox.lalamove.com/v3/orders/'.self::PROVIDER_ID, $request->url());

            return Http::response(['data' => $this->provider($order)]);
        });

        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('COMPLETED', $order->delivery_last_status);
        $this->assertNotNull($order->provider_completed_at);
        $this->assertNotNull($order->customer_confirmed_at);
        $this->assertSame(5, $order->review()->sole()->rating);
        $this->assertSame('', $order->review()->sole()->comment);
        $this->assertSame($financial, $this->financialState($order));
        $confirmed = $order->getRawOriginal('customer_confirmed_at');
        $this->post('/orders/'.$order->id.'/receipt', $this->receipt(['rating' => 1, 'comment' => 'A repeated request must not replace the first rating.']))
            ->assertSessionHasNoErrors();
        $this->assertSame($confirmed, $order->fresh()->getRawOriginal('customer_confirmed_at'));
        $this->assertSame(5, $order->review()->sole()->rating);
        $this->assertDatabaseCount('order_reviews', 1);
        $this->assertSame(1, ActivityLog::where('action', 'order.received')->count());
        Http::assertSentCount(1);
    }

    public static function incompleteStatuses(): array
    {
        return [['ASSIGNING_DRIVER'], ['ON_GOING'], ['PICKED_UP'], ['CANCELED'], ['REJECTED'], ['EXPIRED']];
    }

    #[DataProvider('incompleteStatuses')]
    public function test_provider_has_not_completed_delivery_so_customer_cannot_confirm_receipt(string $status): void
    {
        $order = $this->order();
        $financial = $this->financialState($order);
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order, $status)])]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasErrors('receipt');
        $this->assertReceiptNotSaved($order, $financial);
        $this->assertSame($status, $order->fresh()->delivery_last_status);
        Http::assertSentCount(1);
    }

    public static function failedProviderChecks(): array
    {
        return [['http'], ['empty'], ['different-id'], ['different-order'], ['unknown-status']];
    }

    #[DataProvider('failedProviderChecks')]
    public function test_failed_or_mismatched_provider_check_does_not_change_order_or_expose_provider_details(string $failure): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $attempts = DB::table('delivery_attempts')->get()->toJson();
        $financial = $this->financialState($order);
        $data = $this->provider($order);
        if ($failure === 'different-id') { $data['orderId'] = '107900701999'; }
        if ($failure === 'different-order') { $data['metadata']['order_number'] = 'OTHER-PRIVATE-INVOICE'; }
        if ($failure === 'unknown-status') { $data['status'] = 'UNRECOGNIZED'; }
        $response = $failure === 'empty' ? [] : ['data' => $data];
        Http::fake(['*/v3/orders/*' => Http::response($response + ['private_provider_detail' => 'Never expose this response'], $failure === 'http' ? 500 : 200)]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasErrors(['receipt' => self::CHECK_FAILED]);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($attempts, DB::table('delivery_attempts')->get()->toJson());
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertSentCount(1);
    }

    public function test_a_different_booking_saved_during_the_check_cannot_be_confirmed_by_the_old_provider_response(): void
    {
        $order = $this->order();
        $financial = $this->financialState($order);
        Http::fake(function () use ($order) {
            $data = $this->provider($order);
            Order::whereKey($order->id)->update(['delivery_id' => '107900701999']);

            return Http::response(['data' => $data]);
        });
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasErrors(['receipt' => self::CHECK_FAILED]);
        $this->assertSame('107900701999', $order->fresh()->delivery_id);
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertSentCount(1);
    }

    public static function invalidInputs(): array
    {
        return [['received', '0'], ['rating', 0], ['rating', 6], ['rating', null], ['comment', ['invalid']]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_receipt_input_is_rejected_before_any_provider_request(string $field, mixed $value): void
    {
        $order = $this->order();
        Http::fake();
        $financial = $this->financialState($order);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt([$field => $value]))
            ->assertSessionHasErrors($field);
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertNothingSent();
    }

    public static function unauthorizedActors(): array
    {
        return [['foreign'], ['staff'], ['admin']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_foreign_customer_and_store_roles_cannot_trigger_receipt_verification(string $role): void
    {
        $order = $this->order();
        Http::fake();
        $financial = $this->financialState($order);
        $actor = $role === 'foreign' ? User::factory()->create(['role' => 'customer']) : $order->user;
        if ($role !== 'foreign') { $actor->forceFill(['role' => $role])->save(); }
        $this->actingAs($actor)->post('/orders/'.$order->id.'/receipt', $this->receipt())->assertForbidden();
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertNothingSent();
    }

    public static function ineligibleOrders(): array
    {
        return [
            'unpaid' => [['payment_status' => 'pending']],
            'no verified payment' => [['payment_id' => null]],
            'no courier booking' => [['delivery_id' => null]],
            'pickup' => [['delivery_method' => 'pickup']],
            'packing' => [['status' => 'packing']],
            'not booked' => [['status' => 'ready']],
            'uncertain booking' => [['status' => 'delivery_booking']],
            'cancelled' => [['status' => 'cancelled']],
            'released stock' => [['inventory_released' => true]],
            'uncommitted stock' => [['inventory_committed' => false]],
            'walk-in sale' => [['sales_channel' => 'walk_in']],
        ];
    }

    #[DataProvider('ineligibleOrders')]
    public function test_an_ineligible_order_does_not_contact_the_provider(array $changes): void
    {
        $order = $this->order();
        Http::fake();
        $order->update($changes);
        $financial = $this->financialState($order);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasErrors('receipt');
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertNothingSent();
    }

    public function test_missing_courier_configuration_does_not_contact_provider_or_save_receipt(): void
    {
        $order = $this->order();
        Http::fake();
        $financial = $this->financialState($order);
        config(['shop.lalamove.key' => '']);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasErrors('receipt');
        $this->assertReceiptNotSaved($order, $financial);
        Http::assertNothingSent();
    }

    public function test_previously_verified_courier_completion_allows_receipt_without_another_provider_request(): void
    {
        $order = $this->order();
        Http::fake();
        $order->update(['provider_completed_at' => now(), 'delivery_last_status' => 'COMPLETED']);
        $financial = $this->financialState($order);
        config(['shop.lalamove.key' => '']);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 1);
        $this->assertSame($financial, $this->financialState($order));
        Http::assertNothingSent();
    }

    public function test_attention_state_can_be_reconciled_before_the_customer_confirms_receipt(): void
    {
        $order = $this->order();
        $order->update(['status' => 'delivery_attention']);
        $financial = $this->financialState($order);
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order)])]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())
            ->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->customer_confirmed_at);
        $this->assertSame($financial, $this->financialState($order));
        Http::assertSentCount(1);
    }
}
