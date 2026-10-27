<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LalamoveCreditRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_credit_fixture', 'shop.lalamove.secret' => 'sk_test_credit_fixture',
            'shop.lalamove.address' => 'Synthetic pickup', 'shop.lalamove.lat' => '14.5', 'shop.lalamove.lng' => '121',
            'shop.lalamove.phone' => '09123456789', 'shop.lalamove.service' => 'MOTORCYCLE']);
        Http::preventStrayRequests();
        $staff = User::factory()->create();
        $staff->forceFill(['role' => 'staff'])->save();
        $this->actingAs($staff);
    }

    private function invoice(): array
    {
        $product = Product::create(['name' => 'Synthetic credit fixture', 'slug' => 'credit-fixture', 'category' => '', 'description' => 'Isolated fixture']);
        $variant = $product->variants()->create(['sku' => 'CREDIT-WHITE-M', 'size' => 'M', 'color' => 'White', 'stock' => 18,
            'reserved' => 0, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
        $order = Order::create(['number' => 'ZOT-CREDIT-FIXTURE', 'user_id' => User::factory()->create()->id,
            'tier' => 'retail', 'quantity' => 2, 'subtotal' => 30000, 'merchandise_base' => 30000, 'discount' => 0,
            'shipping' => 10000, 'total' => 40000, 'amount_paid' => 40000, 'payment_status' => 'paid', 'paid_at' => now(),
            'status' => 'ready', 'inventory_committed' => true, 'inventory_released' => false,
            'recipient' => 'Synthetic customer', 'phone' => '09123456780', 'address' => 'Synthetic destination',
            'delivery_method' => 'lalamove', 'lat' => '14.6', 'lng' => '121.1']);
        $order->items()->create(['variant_id' => $variant->id, 'name' => $product->name, 'size' => 'M', 'color' => 'White',
            'quantity' => 2, 'unit_price' => 15000, 'total' => 30000]);
        $order->payments()->create(['reference' => (string) \Illuminate\Support\Str::uuid(), 'method' => 'cash', 'status' => 'paid',
            'amount' => 40000, 'received_at' => now(), 'reason' => 'Synthetic confirmed payment fixture.']);

        return [$order, $variant];
    }

    private function financialState(Order $order, Variant $variant): array
    {
        return ['invoice' => array_intersect_key($order->fresh()->getAttributes(), array_flip(['payment_status', 'amount_paid', 'paid_at', 'subtotal', 'merchandise_base', 'discount',
            'shipping', 'total', 'inventory_committed', 'inventory_released'])), 'inventory' => $variant->fresh()->getAttributes(),
            'payments' => $order->payments()->get()->toArray(), 'items' => $order->items()->get()->toArray()];
    }

    private function responses(array|string $body, ?int $status): void
    {
        Http::fake(['*/v3/quotations' => Http::response(['data' => ['quotationId' => 'quote_credit_fixture',
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']],
            'priceBreakdown' => ['currency' => 'PHP', 'total' => '100.00']]], 201),
            '*/v3/orders' => $status === null ? Http::failedConnection() : Http::response($body, $status)]);
    }

    public static function explicitCreditRejections(): array
    {
        return ['documented top-level code' => [['message' => 'ERR_INSUFFICIENT_CREDIT']],
            'observed structured code' => [['errors' => [['id' => 'ERR_INSUFFICIENT_CREDIT', 'message' => 'Synthetic credit rejection.']]]]];
    }

    #[DataProvider('explicitCreditRejections')]
    public function test_explicit_insufficient_credit_rejection_returns_to_ready_without_changing_money_or_inventory(array $body): void
    {
        [$order, $variant] = $this->invoice();
        $before = $this->financialState($order, $variant);
        $this->responses($body, 402);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_booking_context);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertStringContainsString('credit', session('errors')->first('delivery'));
        $this->assertStringContainsString('No delivery was booked', session('errors')->first('delivery'));
        $this->assertStringNotContainsString('Synthetic credit rejection', session('errors')->first('delivery'));
        $this->assertSame($before, $this->financialState($order, $variant));
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertSentCount(2);
    }

    public static function uncertainResults(): array
    {
        $known = ['message' => 'ERR_INSUFFICIENT_CREDIT'];

        return ['unknown 402' => [['message' => 'ERR_UNKNOWN'], 402],
            'malformed 402' => ['{invalid-json', 402],
            '401 with known code' => [$known, 401],
            '500 with known code' => [$known, 500],
            'success status with error body' => [$known, 201],
            '402 with top-level order ID' => [$known + ['orderId' => 'fixture-provider-order'], 402],
            '402 with data order ID' => [$known + ['data' => ['orderId' => 'fixture-provider-order']], 402],
            '402 with success marker' => [$known + ['success' => true], 402],
            '402 with unexpected data' => [$known + ['data' => null], 402],
            '402 with conflicting error' => [['errors' => [['id' => 'ERR_INSUFFICIENT_CREDIT'], ['id' => 'ERR_UNKNOWN']]], 402],
            '402 with malformed structured errors' => [['errors' => ['id' => 'ERR_INSUFFICIENT_CREDIT']], 402],
            '402 with nested order ID' => [['errors' => [['id' => 'ERR_INSUFFICIENT_CREDIT', 'orderId' => 'fixture-provider-order']]], 402],
            'connection timeout' => ['', null]];
    }

    #[DataProvider('uncertainResults')]
    public function test_other_results_keep_the_claim_and_block_another_booking(array|string $body, ?int $status): void
    {
        [$order, $variant] = $this->invoice();
        $before = $this->financialState($order, $variant);
        $this->responses($body, $status);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivery_booking_context);
        $this->assertNull($order->fresh()->delivery_id);
        $this->assertStringNotContainsString('No delivery was booked', session('errors')->first('delivery'));
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame($before, $this->financialState($order, $variant));
        $this->assertDatabaseCount('delivery_attempts', 0);
        Http::assertSentCount(2);
    }
}
