<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerReceiptGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function order(string $method = 'lalamove'): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Receipt guidance tee', 'slug' => 'receipt-guide-'.uniqid(), 'category' => '', 'description' => 'Clothing']);
        $variant = $product->variants()->create(['sku' => 'GUIDE-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 10, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Receipt customer', 'phone' => '09123456789', 'address' => 'Test address', 'delivery_method' => $method, 'shipping' => 0], $customer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_guidance_'.$order->id));
        $order->refresh()->update(['status' => $method === 'pickup' ? 'ready' : 'out_for_delivery', 'provider_completed_at' => $method === 'pickup' ? null : now()]);

        return $order->fresh();
    }

    public static function readyMethods(): array
    {
        return ['pickup' => ['pickup'], 'verified courier' => ['lalamove']];
    }

    #[DataProvider('readyMethods')]
    public function test_ready_receipt_is_discoverable_in_purchases_dashboard_and_order_summary(string $method): void
    {
        $order = $this->order($method);
        $this->actingAs($order->user);
        $anchor = route('orders.show', $order).'#order-receipt';
        foreach (['/orders', '/dashboard'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.$anchor.'"', false)->assertSee('Order received');
        }
        $page = $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('href="#order-receipt"', false)->assertSee('id="order-receipt"', false)
            ->assertSee('name="rating"', false)->assertSee('name="received"', false)
            ->assertSee('written review if you want to; it is optional.')
            ->assertSee('Confirm Delivered');
        $this->assertSame(5, substr_count($page->getContent(), 'type="radio" name="rating"'));
        $this->assertLessThan(strpos($page->getContent(), 'Invoice payments'), strpos($page->getContent(), 'id="order-receipt"'));
        Http::assertNothingSent();
    }

    public static function unavailableOrders(): array
    {
        return [
            'courier not verified' => [['provider_completed_at' => null]],
            'still packing' => [['status' => 'packing']],
            'missing payment reference' => [['payment_id' => null]],
            'not fully paid' => [['payment_status' => 'pending']],
            'cancelled' => [['status' => 'cancelled']],
            'stock released' => [['inventory_released' => true]],
            'walk-in' => [['sales_channel' => 'walk_in']],
        ];
    }

    #[DataProvider('unavailableOrders')]
    public function test_quick_receipt_action_does_not_bypass_existing_delivery_or_payment_conditions(array $attributes): void
    {
        $order = $this->order();
        $order->update($attributes);
        $this->actingAs($order->user);
        $this->get('/orders')->assertOk()->assertDontSee('href="'.route('orders.show', $order).'#order-receipt"', false);
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertNothingSent();
    }

    public function test_waiting_customer_receives_clear_instructions_without_a_receipt_submit_form(): void
    {
        $order = $this->order();
        $order->update(['provider_completed_at' => null]);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Order received? View instructions')
            ->assertSee('Receipt confirmation is not available yet.')
            ->assertSee('ask store staff to check the original order.')
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertNothingSent();
    }

    public function test_stars_only_confirmation_shows_saved_receipt_and_refund_steps_without_financial_changes(): void
    {
        $order = $this->order();
        $before = $order->only(['payment_status', 'payment_id', 'amount_paid', 'total', 'inventory_committed']);
        $stock = Variant::first()->only(['stock', 'reserved']);
        $this->actingAs($order->user)->post(route('orders.receipt', $order), ['rating' => 5, 'received' => '1'])
            ->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame($before, $order->only(array_keys($before)));
        $this->assertSame($stock, Variant::first()->only(['stock', 'reserved']));
        $this->assertSame('', $order->review()->sole()->comment);
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('Order received · Delivered')->assertSee($order->customer_confirmed_at->format('M d, Y · g:i A'))
            ->assertSee('How to request a return or refund')->assertSee('required clear photo')
            ->assertSee('within 7 days after confirmed delivery')->assertSee('online refund is complete only after PayMongo confirms it.')
            ->assertSee('id="refunds-returns"', false)
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        $this->get('/orders?status=completed')->assertOk()
            ->assertSee('Order received '.$order->customer_confirmed_at->format('M d, Y'))
            ->assertDontSee('href="'.route('orders.show', $order).'#order-receipt"', false);
        Http::assertNothingSent();
    }

    public function test_earlier_confirmed_order_without_review_shows_received_status_instead_of_another_confirmation(): void
    {
        $order = $this->order();
        $order->update(['status' => 'completed', 'customer_confirmed_at' => now()->subDay()]);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Order received · Delivered')->assertSee('No rating was recorded for this earlier order.')
            ->assertDontSee('Receipt confirmation is not available yet.')
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        $this->assertDatabaseCount('order_reviews', 0);
        Http::assertNothingSent();
    }

    public function test_staff_cannot_see_customer_quick_action_or_confirm_customer_receipt(): void
    {
        $order = $this->order();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Only the customer can confirm receipt')
            ->assertDontSee('href="#order-receipt"', false)
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        $this->post(route('orders.receipt', $order), ['rating' => 5, 'received' => '1'])->assertForbidden();
        $this->assertNull($order->fresh()->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 0);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_view_or_confirm_someone_elses_order(): void
    {
        $order = $this->order();
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get(route('orders.show', $order))->assertForbidden();
        $this->post(route('orders.receipt', $order), ['rating' => 5, 'received' => '1'])->assertForbidden();
        $this->assertNull($order->fresh()->customer_confirmed_at);
        Http::assertNothingSent();
    }
}
