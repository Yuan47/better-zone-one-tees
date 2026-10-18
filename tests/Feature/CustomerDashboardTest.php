<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function order(User $user, string $number, array $overrides = []): Order
    {
        return Order::create($overrides + [
            'number' => $number, 'user_id' => $user->id, 'tier' => 'retail', 'quantity' => 2,
            'subtotal' => 20000, 'merchandise_base' => 20000, 'total' => 20000,
            'recipient' => 'Dashboard Customer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'payment_status' => 'pending', 'status' => 'awaiting_payment',
        ]);
    }

    public function test_guest_must_sign_in_and_new_customer_has_clear_empty_states_and_shortcuts(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('dashboard'))->assertOk()
            ->assertSee('My dashboard')->assertSee('No orders yet')->assertSee('Favorites / wishlist')
            ->assertSee('href="'.route('orders').'"', false)
            ->assertSee('href="'.route('store', ['wishlist' => 1]).'"', false)
            ->assertSee('href="'.route('account').'"', false)
            ->assertViewHas('orderCount', 0)->assertViewHas('openInvoiceCount', 0)
            ->assertViewHas('outstandingBalance', 0)->assertViewHas('favoriteCount', 0);
        Http::assertNothingSent();
    }

    public static function workspaceRoles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('workspaceRoles')]
    public function test_workspace_accounts_are_redirected_to_their_existing_dashboard(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
        Http::assertNothingSent();
    }

    public function test_history_summary_contains_only_owned_orders_and_uses_recorded_payment_balances(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $paid = $this->order($customer, 'DASHBOARD-PAID', ['payment_status' => 'paid', 'status' => 'packing']);
        $partial = $this->order($customer, 'DASHBOARD-PARTIAL', ['payment_status' => 'partially_paid', 'amount_paid' => 5000,
            'due_at' => now()->addDays(7), 'original_due_at' => now()->addDays(7)]);
        $this->order($customer, 'DASHBOARD-CANCELLED', ['status' => 'cancelled']);
        $this->order($customer, 'DASHBOARD-RELEASED', ['inventory_released' => true]);
        $this->order(User::factory()->create(), 'OTHER-CUSTOMER-PRIVATE', ['total' => 9999900]);

        $this->actingAs($customer)->get(route('dashboard'))->assertOk()
            ->assertViewHas('orderCount', 4)->assertViewHas('openInvoiceCount', 1)
            ->assertViewHas('outstandingBalance', 15000)
            ->assertSee($paid->number)->assertSee($partial->number)->assertSee('Packing')
            ->assertSee('₱150.00')->assertSee('Payment received')->assertSee('Due ')
            ->assertDontSee('OTHER-CUSTOMER-PRIVATE')
            ->assertViewHas('recentOrders', fn ($orders) => $orders->every(fn ($order) => $order->user_id === $customer->id));
        $this->assertDatabaseCount('orders', 5);
        $this->assertSame(5000, $partial->fresh()->amount_paid);
        Http::assertNothingSent();
    }

    public function test_recent_orders_are_limited_while_complete_history_stays_available(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        for ($i = 1; $i <= 6; $i++) {
            $this->order($customer, 'DASHBOARD-ORDER-'.$i);
        }
        $this->actingAs($customer)->get(route('dashboard'))->assertOk()
            ->assertViewHas('orderCount', 6)->assertViewHas('recentOrders', fn ($orders) => $orders->count() === 4)
            ->assertSee('DASHBOARD-ORDER-6')->assertSee('DASHBOARD-ORDER-3')->assertDontSee('DASHBOARD-ORDER-2')
            ->assertSee('Full order history');
        $this->get(route('orders'))->assertOk()->assertSee('DASHBOARD-ORDER-1')->assertSee('DASHBOARD-ORDER-6');
    }

    public function test_favorites_count_and_link_use_only_the_customers_visible_saved_products(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create();
        foreach ([['owned-visible', $customer, true], ['owned-archived', $customer, false], ['other-visible', $other, true]] as [$slug, $owner, $active]) {
            $product = Product::create(['name' => $slug, 'slug' => $slug, 'category' => '', 'description' => 'Favorite fixture', 'active' => $active]);
            DB::table('wishlists')->insert(['user_id' => $owner->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($customer)->get(route('dashboard'))->assertOk()->assertViewHas('favoriteCount', 1)
            ->assertSee('href="'.route('store', ['wishlist' => 1]).'"', false);
        $this->get(route('store', ['wishlist' => 1]))->assertOk()->assertSee('owned-visible')
            ->assertDontSee('owned-archived')->assertDontSee('other-visible');
        $this->assertDatabaseCount('wishlists', 3);
    }
}
