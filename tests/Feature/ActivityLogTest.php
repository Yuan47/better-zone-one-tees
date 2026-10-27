<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer', string $name = 'Sample Actor'): User
    {
        $user = User::factory()->create(['name' => $name, 'password' => 'OriginalPassword!2026']);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Activity Test Tee', 'slug' => 'activity-test-tee', 'category' => '', 'description' => 'Demo clothing']);

        return $product->variants()->create(['sku' => 'ACTIVITY-M-RED', 'size' => 'M', 'color' => 'Red', 'color_group' => 'COLORED',
            'stock' => 100, 'retail_price' => 16000, 'wholesale_price' => 15000, 'reseller_price' => 14000])->fresh();
    }

    public function test_activity_log_is_admin_only_and_has_no_delete_endpoint(): void
    {
        $this->get('/admin/activity')->assertRedirect('/admin/login');
        foreach (['customer', 'staff'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/activity')->assertForbidden();
        }
        $this->actingAs($this->user('admin'))->get('/admin/activity')->assertOk()->assertSee('Activity Log');
        $this->delete('/admin/activity')->assertStatus(405);
    }

    public function test_successful_login_logout_and_registration_capture_the_right_actor(): void
    {
        $staff = $this->user('staff', 'Staff Member');
        $this->post('/admin/login', ['email' => $staff->email, 'password' => 'OriginalPassword!2026'])->assertRedirect('/admin');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $staff->id, 'actor_name' => 'Staff Member', 'actor_role' => 'staff']);
        $this->post('/logout')->assertRedirect('/admin/login');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'actor_id' => $staff->id, 'actor_name' => 'Staff Member', 'actor_role' => 'staff']);
        $this->post('/register', ['name' => 'New Customer', 'username' => 'new_customer', 'email' => 'newcustomer@example.com', 'password' => 'RegisterPassword!2026', 'password_confirmation' => 'RegisterPassword!2026'])
            ->assertRedirect('/');
        $customer = User::where('email', 'newcustomer@example.com')->firstOrFail();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.registered', 'actor_id' => $customer->id, 'actor_name' => 'New Customer', 'actor_role' => 'customer']);
        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_profile_and_password_logs_contain_field_names_without_sensitive_values(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patch('/account', ['name' => 'Updated Actor', 'username' => 'updated_actor', 'phone' => '09123456789',
            'street_address' => 'Private123 Street', 'city' => 'Private City', 'province' => 'Private Province', 'postal_code' => '1234', 'country' => 'Philippines'])
            ->assertRedirect('/account');
        $profile = ActivityLog::where('action', 'account.updated')->sole();
        $this->assertContains('street_address', $profile->metadata['fields']);
        $this->assertContains('username', $profile->metadata['fields']);
        $this->patch('/account/password', ['current_password' => 'OriginalPassword!2026', 'password' => 'NewSecretPassword!2026', 'password_confirmation' => 'NewSecretPassword!2026'])
            ->assertRedirect('/account');
        $this->assertDatabaseHas('activity_logs', ['action' => 'account.password_changed', 'actor_id' => $user->id]);
        $contents = ActivityLog::all()->toJson();
        foreach (['Private123 Street', 'Private City', 'Private Province', '09123456789', 'OriginalPassword!2026', 'NewSecretPassword!2026', $user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
        $this->assertSame([], ActivityLog::where('action', 'account.password_changed')->sole()->metadata);
    }

    public function test_cart_and_wishlist_changes_are_logged_but_unchanged_cart_quantity_is_not(): void
    {
        $variant = $this->variant();
        $user = $this->user();
        $this->actingAs($user)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect('/cart');
        $this->assertDatabaseHas('activity_logs', ['action' => 'cart.added', 'actor_id' => $user->id, 'target_type' => 'variant', 'target_id' => $variant->id]);
        $this->patch('/cart/'.$variant->id, ['quantity' => 2])->assertRedirect();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->patch('/cart/'.$variant->id, ['quantity' => 3])->assertRedirect();
        $this->patch('/cart/'.$variant->id, ['quantity' => 0])->assertRedirect();
        $this->post('/wishlist/'.$variant->product_id)->assertRedirect();
        $this->post('/wishlist/'.$variant->product_id)->assertRedirect();
        $this->assertSame(['cart.added', 'cart.updated', 'cart.removed', 'wishlist.added', 'wishlist.removed'], ActivityLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(2, ActivityLog::where('action', 'cart.updated')->sole()->metadata['previous_quantity']);
        $this->assertSame(3, ActivityLog::where('action', 'cart.updated')->sole()->metadata['quantity']);
    }

    public function test_failed_attempts_unchanged_profile_and_passive_browsing_do_not_create_logs(): void
    {
        $variant = $this->variant();
        $user = $this->user();
        $user->update(['username' => 'sample_actor']);
        $user = $user->fresh();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->actingAs($user)->patch('/account', ['name' => $user->name, 'username' => 'sample_actor'])->assertRedirect('/account');
        $this->patch('/account', ['name' => '', 'username' => 'changed'])->assertSessionHasErrors('name');
        $this->patch('/account/password', ['current_password' => 'wrong-password', 'password' => 'AnotherPassword!2026', 'password_confirmation' => 'AnotherPassword!2026'])->assertSessionHasErrors('current_password');
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 101])->assertSessionHasErrors('stock');
        foreach (['/', '/account', '/cart', '/products/'.$variant->product->slug] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_new_order_is_recorded_even_when_provider_outcome_is_unknown_and_retry_is_not_duplicated(): void
    {
        $variant = $this->variant();
        $user = $this->user();
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Provider timeout'));
        });
        $details = ['recipient' => 'Buyer', 'phone' => '09123456789', 'delivery_method' => 'pickup'];
        $this->actingAs($user)->withSession(['cart' => [$variant->id => 2]])->post('/checkout', $details)->assertRedirect();
        $order = Order::sole();
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.created', 'actor_id' => $user->id, 'target_type' => 'order', 'target_id' => $order->id]);
        $this->assertSame(['quantity' => 2, 'delivery_method' => 'pickup', 'status' => 'awaiting_payment'], ActivityLog::sole()->metadata);
        $this->post('/checkout', $details)->assertRedirect('/orders/'.$order->id);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_staff_order_status_changes_are_logged_and_rejected_transition_is_not(): void
    {
        $variant = $this->variant();
        $order = app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Buyer', 'phone' => '09123456789', 'delivery_method' => 'pickup', 'address' => 'Store pickup', 'shipping' => 0], $this->user()->id);
        $order->update(['payment_status' => 'paid', 'status' => 'packing']);
        $staff = $this->user('staff');
        $reason = 'The clothing has been packed and checked for pickup.';
        $this->actingAs($staff)->patch('/admin/orders/'.$order->id, ['status' => 'ready'])->assertSessionHasErrors('reason');
        $this->assertSame('packing', $order->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->patch('/admin/orders/'.$order->id, ['status' => 'ready', 'reason' => $reason])->assertRedirect()->assertSessionHasNoErrors();
        $log = ActivityLog::sole();
        $this->assertSame('order.status_changed', $log->action);
        $this->assertSame('staff', $log->actor_role);
        $this->assertSame(['previous_status' => 'packing', 'status' => 'ready', 'reason' => $reason], $log->metadata);
        $this->patchJson('/admin/orders/'.$order->id, ['status' => 'completed', 'reason' => 'Staff must not confirm receipt for the customer.'])->assertUnprocessable();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_admin_product_changes_and_report_export_are_logged_without_description_text(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/admin/products', ['name' => 'New Catalog Product', 'description' => 'Private product description value', 'active' => 1])->assertRedirect();
        $product = Product::sole();
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.created', 'target_type' => 'product', 'target_id' => $product->id]);
        $this->patch('/admin/products/'.$product->id, ['name' => $product->name, 'description' => $product->description, 'active' => 1, 'size_chart' => []])->assertRedirect();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->get('/admin/reports/export')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'reports.exported', 'actor_id' => $admin->id]);
        $this->assertSame(['format' => 'csv'], ActivityLog::where('action', 'reports.exported')->sole()->metadata);
        $this->assertStringNotContainsString('Private product description value', ActivityLog::all()->toJson());
    }

    public function test_uncertain_delivery_is_recorded_once_and_rejected_retry_creates_no_new_activity(): void
    {
        $variant = $this->variant();
        $order = app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Buyer', 'phone' => '09123456789', 'delivery_method' => 'lalamove', 'address' => 'Private destination', 'shipping' => 10000], $this->user()->id);
        $order->update(['payment_status' => 'paid', 'status' => 'ready']);
        $this->mock(Lalamove::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('book')->once()->andThrow(new \RuntimeException('Uncertain provider timeout'));
        });
        $this->actingAs($this->user('staff'))->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $log = ActivityLog::sole();
        $this->assertSame('order.delivery_reconciliation_required', $log->action);
        $this->assertSame(['status' => 'delivery_booking'], $log->metadata);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertStringNotContainsString('Private destination', $log->toJson());
    }

    public function test_variant_and_color_actions_use_saved_targets_and_skip_unchanged_variants(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->user('admin'));
        $data = ['size' => 'M', 'color_group' => 'COLORED', 'color' => 'Red', 'stock' => 100, 'expected_stock' => 100, 'reorder_level' => $variant->reorder_level,
            'retail' => 160, 'wholesale' => 150, 'reseller' => 140];
        $this->patch('/admin/products/'.$variant->product_id.'/variants/'.$variant->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('activity_logs', 0);
        $data['stock'] = 110;
        $this->patch('/admin/products/'.$variant->product_id.'/variants/'.$variant->id, $data)->assertRedirect();
        $log = ActivityLog::sole();
        $this->assertSame('variant.updated', $log->action);
        $this->assertSame($variant->id, $log->target_id);
        $this->assertSame(['fields' => ['stock']], $log->metadata);
        $this->post('/admin/products/'.$variant->product_id.'/colors', ['color' => 'Blue'])->assertRedirect();
        $this->assertSame(['variant_count' => 1], ActivityLog::where('action', 'catalog.color_added')->sole()->metadata);
    }

    public function test_recorder_whitelists_metadata_and_never_records_guest_or_unknown_events(): void
    {
        $recorder = app(ActivityRecorder::class);
        $user = $this->user();
        $log = $recorder->record($user, 'account.updated', $user, ['fields' => ['name', 'password', 'session_token', 'street_address'],
            'password' => 'super-secret', 'address' => 'Private place', 'ip' => '127.0.0.1', 'token' => 'secret-token', 'raw_input' => ['email' => 'private@example.com']]);
        $this->assertSame(['fields' => ['name', 'street_address']], $log->metadata);
        $this->assertNull($recorder->record(null, 'auth.login'));
        $this->assertNull($recorder->record($user, 'unknown.action'));
        $bad = $recorder->record($user, 'cart.added', null, ['quantity' => 'secret-string', 'cart_quantity' => -1, 'previous_quantity' => 2, 'message' => 'private-chat']);
        $this->assertSame(['previous_quantity' => 2], $bad->metadata);
        $this->assertStringNotContainsString('super-secret', ActivityLog::all()->toJson());
        $this->assertStringNotContainsString('Private place', ActivityLog::all()->toJson());
        $this->assertStringNotContainsString('private-chat', ActivityLog::all()->toJson());
    }

    public function test_admin_can_search_filter_dates_roles_and_events_and_page_results(): void
    {
        $recorder = app(ActivityRecorder::class);
        $admin = $this->user('admin', 'Administrator');
        $customer = $this->user('customer', 'Alice Customer');
        $staff = $this->user('staff', 'Staff Member');
        $old = $recorder->record($customer, 'cart.added', null, ['quantity' => 1]);
        $old->update(['occurred_at' => '2026-10-10 12:00:00']);
        $match = $recorder->record($customer, 'cart.added', null, ['quantity' => 2]);
        $match->update(['occurred_at' => '2026-10-11 12:00:00']);
        $recorder->record($staff, 'reports.exported', null, ['format' => 'csv']);
        $this->actingAs($admin)->get('/admin/activity?q=Alice&role=customer&event=cart.added&from=2026-10-11&to=2026-10-11')
            ->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->id === $match->id);
        $this->get('/admin/activity?q=Exported+a+sales+report')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 1);
        $this->get('/admin/activity?to=2026-10-10')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 1);
        for ($i = 0; $i < 28; $i++) {
            $recorder->record($admin, 'auth.login', $admin);
        }
        $this->get('/admin/activity')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 31 && $logs->count() === 25)->assertSee('Next');
        $this->get('/admin/activity?page=2')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 6)->assertSee('Previous');
        $this->assertDatabaseCount('activity_logs', 31);
    }

    public function test_actor_snapshot_survives_profile_changes_and_is_escaped_in_view(): void
    {
        $user = $this->user('customer', '<script>unsafe</script>');
        $log = app(ActivityRecorder::class)->record($user, 'auth.login', $user);
        $user->update(['name' => 'New Name']);
        $this->assertSame('<script>unsafe</script>', $log->fresh()->actor_name);
        $this->actingAs($this->user('admin'))->get('/admin/activity')->assertOk()
            ->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertDontSee('<script>unsafe</script>', false);
    }
}
