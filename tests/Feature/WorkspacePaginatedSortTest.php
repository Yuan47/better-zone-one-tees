<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Refund;
use App\Models\StaffInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspacePaginatedSortTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private int $invoiceNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
        $this->buyer = User::factory()->create(['role' => 'customer']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function invoice(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'number' => 'SORT-'.sprintf('%04d', ++$this->invoiceNumber), 'user_id' => $this->buyer->id,
            'tier' => 'retail', 'quantity' => 1, 'subtotal' => 10000, 'merchandise_base' => 10000, 'discount' => 0,
            'shipping' => 0, 'total' => 10000, 'amount_paid' => 0, 'payment_status' => 'pending',
            'status' => 'awaiting_payment', 'recipient' => 'Guest buyer', 'phone' => '09123456789', 'address' => 'Test pickup',
            'delivery_method' => 'pickup', 'created_at' => now()->subMinutes($this->invoiceNumber),
        ]);
    }

    private function ids($response, string $key): array
    {
        return $response->assertOk()->viewData($key)->pluck('id')->all();
    }

    private function headerUrl($response, string $label): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $anchor = $xpath->query('//thead/tr/th/a[starts-with(@aria-label, "'.$label.':")]')->item(0);
        $this->assertNotNull($anchor, 'Sortable header must exist for '.$label);

        return $anchor->getAttribute('href');
    }

    public function test_orders_sort_the_whole_filtered_result_before_pagination_and_keep_filters_on_links_and_forms(): void
    {
        $orders = [];
        for ($i = 1; $i <= 31; $i++) {
            $orders[] = $this->invoice(['total' => $i * 100, 'recipient' => 'Matching buyer']);
        }
        $this->invoice(['total' => 999999, 'payment_status' => 'paid', 'recipient' => 'Matching buyer']);
        $parameters = ['q' => 'Matching', 'payment_status' => 'pending', 'sort' => 'total', 'direction' => 'desc'];
        $first = $this->get(route('admin.orders', $parameters));
        $this->assertSame(array_reverse(array_map(fn ($order) => $order->id, array_slice($orders, 11))), $this->ids($first, 'orders'));
        $second = $this->get(route('admin.orders', $parameters + ['page' => 2]));
        $this->assertSame(array_reverse(array_map(fn ($order) => $order->id, array_slice($orders, 0, 11))), $this->ids($second, 'orders'));
        $this->assertStringContainsString('payment_status=pending', $first->viewData('orders')->nextPageUrl());
        $this->assertStringContainsString('sort=total', $first->viewData('orders')->nextPageUrl());
        $first->assertSee('name="sort" value="total"', false)->assertSee('name="direction" value="desc"', false);
        parse_str(parse_url($this->headerUrl($second, 'Quantity / tier'), PHP_URL_QUERY), $next);
        $this->assertSame('Matching', $next['q']);
        $this->assertSame('pending', $next['payment_status']);
        $this->assertArrayNotHasKey('page', $next);
        Http::assertNothingSent();
    }

    public function test_clicking_an_orders_number_header_cycles_descending_ascending_and_original_order(): void
    {
        $low = $this->invoice(['total' => 100]);
        $high = $this->invoice(['total' => 900]);
        $middle = $this->invoice(['total' => 400]);
        $default = $this->get(route('admin.orders'));
        $this->assertSame([$low->id, $high->id, $middle->id], $this->ids($default, 'orders'));
        $descending = $this->get($this->headerUrl($default, 'Total'));
        $this->assertSame([$high->id, $middle->id, $low->id], $this->ids($descending, 'orders'));
        $descending->assertSee('aria-sort="descending"', false);
        $ascending = $this->get($this->headerUrl($descending, 'Total'));
        $this->assertSame([$low->id, $middle->id, $high->id], $this->ids($ascending, 'orders'));
        $restored = $this->get($this->headerUrl($ascending, 'Total'));
        $this->assertSame($this->ids($default, 'orders'), $this->ids($restored, 'orders'));
    }

    public function test_order_received_outstanding_customer_and_fulfillment_sorts_match_displayed_values(): void
    {
        $closed = $this->invoice(['user_id' => null, 'recipient' => 'Zulu guest', 'total' => 90000, 'status' => 'cancelled']);
        $paid = $this->invoice(['payment_status' => 'paid', 'total' => 70000, 'amount_paid' => 0, 'status' => 'completed', 'delivery_method' => 'lalamove']);
        $partial = $this->invoice(['payment_status' => 'partially_paid', 'total' => 60000, 'amount_paid' => 20000]);
        $delivered = $this->invoice(['payment_status' => 'paid', 'total' => 10000, 'status' => 'completed', 'customer_confirmed_at' => now()]);
        $received = $this->get(route('admin.orders', ['sort' => 'received', 'direction' => 'desc']));
        $this->assertSame([$paid->id, $partial->id, $delivered->id, $closed->id], $this->ids($received, 'orders'));
        $balance = $this->get(route('admin.orders', ['sort' => 'outstanding', 'direction' => 'desc']));
        $this->assertSame($partial->id, $this->ids($balance, 'orders')[0]);
        $balance->assertSee('No payment due');
        $fulfillment = $this->get(route('admin.orders', ['sort' => 'fulfillment', 'direction' => 'asc']));
        $this->assertSame([$partial->id, $closed->id, $delivered->id, $paid->id], $this->ids($fulfillment, 'orders'));
        $this->buyer->update(['name' => 'Alpha account']);
        $customer = $this->get(route('admin.orders', ['sort' => 'customer', 'direction' => 'asc']));
        $customerIds = $this->ids($customer, 'orders');
        $this->assertSame($closed->id, $customerIds[array_key_last($customerIds)]);
    }

    public function test_equal_sorted_values_have_stable_ids_across_order_pages(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->invoice()->id;
        }
        $parameters = ['sort' => 'total', 'direction' => 'desc'];
        $first = $this->ids($this->get(route('admin.orders', $parameters)), 'orders');
        $second = $this->ids($this->get(route('admin.orders', $parameters + ['page' => 2])), 'orders');
        $this->assertSame($ids, array_merge($first, $second));
    }

    public function test_customers_use_global_alphabetical_and_date_sorting_without_including_other_roles(): void
    {
        $this->buyer->update(['name' => 'ZZZ Existing buyer']);
        $people = [];
        for ($i = 25; $i >= 1; $i--) {
            $people[] = User::factory()->create(['name' => 'Customer '.sprintf('%02d', $i), 'created_at' => now()->subDays($i)]);
        }
        User::factory()->create(['name' => 'AAA Staff secret', 'role' => 'staff']);
        $page = $this->get(route('admin.customers', ['sort' => 'name', 'direction' => 'asc']));
        $this->assertSame('Customer 01', $page->viewData('customers')->first()->name);
        $this->assertSame('Customer 20', $page->viewData('customers')->last()->name);
        $page->assertDontSee('AAA Staff secret');
        $dates = $this->get(route('admin.customers', ['sort' => 'joined', 'direction' => 'asc']));
        $this->assertSame('Customer 25', $dates->viewData('customers')->first()->name);
        $names = $this->get($this->headerUrl($this->get(route('admin.customers')), 'Name'));
        $this->assertSame('Customer 01', $names->viewData('customers')->first()->name);
        $names = $this->get($this->headerUrl($names, 'Name'));
        $this->assertSame('ZZZ Existing buyer', $names->viewData('customers')->first()->name);
    }

    public function test_activity_sorts_rendered_labels_across_pages_and_preserves_filters(): void
    {
        $rows = [];
        for ($i = 30; $i >= 1; $i--) {
            $rows[] = ActivityLog::create(['actor_name' => 'Staff '.sprintf('%02d', $i), 'actor_role' => 'staff',
                'action' => $i % 2 ? 'auth.login' : 'product.created', 'target_type' => $i % 2 ? 'order' : 'account', 'target_id' => $i,
                'occurred_at' => now()->subMinutes($i)]);
        }
        ActivityLog::create(['actor_name' => 'AAA customer', 'actor_role' => 'customer', 'action' => 'auth.login', 'occurred_at' => now()]);
        $response = $this->get(route('admin.activity.index', ['role' => 'staff', 'sort' => 'actor', 'direction' => 'asc']));
        $this->assertSame('Staff 01', $response->viewData('logs')->first()->actor_name);
        $this->assertSame(30, $response->viewData('logs')->total());
        $this->assertStringContainsString('role=staff', $response->viewData('logs')->nextPageUrl());
        $response->assertSee('name="sort" value="actor"', false);
        $action = $this->get(route('admin.activity.index', ['role' => 'staff', 'sort' => 'action', 'direction' => 'asc']));
        $this->assertSame('Created a product', $action->viewData('logs')->first()->label());
        $target = $this->get(route('admin.activity.index', ['role' => 'staff', 'sort' => 'target', 'direction' => 'asc']));
        $this->assertStringStartsWith('Account #', $target->viewData('logs')->first()->targetLabel());
        $oldest = $this->get(route('admin.activity.index', ['role' => 'staff', 'sort' => 'when', 'direction' => 'asc']));
        $this->assertSame('Staff 30', $oldest->viewData('logs')->first()->actor_name);
        $restored = $this->get(route('admin.activity.index', ['role' => 'staff']));
        $this->assertSame('Staff 01', $restored->viewData('logs')->first()->actor_name);
    }

    public function test_refund_amount_sorting_is_global_keeps_null_last_and_default_keeps_review_priority(): void
    {
        $order = $this->invoice(['payment_status' => 'paid']);
        $refunds = [];
        for ($i = 1; $i <= 25; $i++) {
            $refunds[] = $order->refunds()->create(['requested_by' => $this->buyer->id, 'requested_amount' => $i * 100,
                'amount' => $i * 100, 'reason' => 'Sorting fixture request.', 'status' => 'succeeded', 'created_at' => now()->subMinutes($i)]);
        }
        $pending = $order->refunds()->create(['requested_by' => $this->buyer->id, 'requested_amount' => 50000, 'reason' => 'Review me first.', 'status' => 'requested', 'created_at' => now()->subDays(10)]);
        $default = $this->get(route('admin.refunds.index'));
        $this->assertSame($pending->id, $default->viewData('refunds')->first()->id);
        foreach (['asc', 'desc'] as $direction) {
            $response = $this->get(route('admin.refunds.index', ['sort' => 'amount', 'direction' => $direction, 'page' => 2]));
            $this->assertSame($pending->id, $response->viewData('refunds')->last()->id);
        }
        $response = $this->get(route('admin.refunds.index', ['status' => 'succeeded', 'sort' => 'requested', 'direction' => 'desc']));
        $this->assertSame($refunds[24]->id, $response->viewData('refunds')->first()->id);
        $this->assertSame(25, $response->viewData('refunds')->total());
        $this->assertStringContainsString('status=succeeded', $response->viewData('refunds')->nextPageUrl());
        $labels = $this->get(route('admin.refunds.index', ['sort' => 'status', 'direction' => 'asc']));
        $this->assertSame('Awaiting review', $labels->viewData('refunds')->first()->label());
    }

    public function test_staff_and_pending_setup_have_independent_global_sort_parameters_and_exclude_consumed_setups(): void
    {
        for ($i = 20; $i >= 1; $i--) {
            User::factory()->create(['name' => 'Team '.sprintf('%02d', $i), 'role' => 'staff']);
            StaffInvitation::create(['name' => 'Setup '.sprintf('%02d', $i), 'email' => 'setup'.$i.'@example.test', 'created_at' => now()->subDays($i)]);
        }
        StaffInvitation::create(['name' => 'AAA consumed secret', 'email' => 'consumed@example.test', 'consumed_at' => now()]);
        $parameters = ['staff_sort' => 'name', 'staff_direction' => 'asc', 'setup_sort' => 'created', 'setup_direction' => 'asc'];
        $response = $this->get(route('admin.staff.index', $parameters));
        $this->assertSame('Team 01', $response->viewData('staff')->first()->name);
        $this->assertSame('Team 15', $response->viewData('staff')->last()->name);
        $this->assertSame('Setup 20', $response->viewData('invitations')->first()->name);
        $this->assertSame(20, $response->viewData('invitations')->total());
        $response->assertDontSee('AAA consumed secret');
        $this->assertStringContainsString('setup_sort=created', $response->viewData('staff')->nextPageUrl());
        $this->assertStringContainsString('staff_sort=name', $response->viewData('invitations')->nextPageUrl());
        $second = $this->get(route('admin.staff.index', $parameters + ['staff_page' => 2, 'setup_page' => 2]));
        $this->assertSame('Team 16', $second->viewData('staff')->first()->name);
        $this->assertSame('Setup 05', $second->viewData('invitations')->first()->name);
    }

    public function test_invalid_sort_values_are_rejected_and_sorting_never_expands_workspace_permissions(): void
    {
        foreach (['admin.orders', 'admin.customers', 'admin.activity.index', 'admin.refunds.index'] as $route) {
            $this->getJson(route($route, ['sort' => 'id desc; DROP TABLE users', 'direction' => 'asc']))->assertUnprocessable();
            $this->getJson(route($route, ['sort' => ['name'], 'direction' => 'asc']))->assertUnprocessable();
        }
        $this->getJson(route('admin.orders', ['sort' => 'total', 'direction' => 'asc; SELECT 1']))->assertUnprocessable();
        foreach (['staff_', 'setup_'] as $prefix) {
            $this->getJson(route('admin.staff.index', [$prefix.'sort' => 'password_hash', $prefix.'direction' => 'desc']))->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->get(route('admin.orders', ['sort' => 'total', 'direction' => 'desc']))->assertOk();
        $this->get(route('admin.refunds.index', ['sort' => 'amount', 'direction' => 'asc']))->assertOk();
        foreach (['admin.customers', 'admin.activity.index', 'admin.staff.index'] as $route) {
            $this->get(route($route, ['sort' => 'name', 'direction' => 'asc']))->assertForbidden();
        }
        $this->actingAs($this->buyer);
        foreach (['admin.orders', 'admin.customers', 'admin.activity.index', 'admin.refunds.index', 'admin.staff.index'] as $route) {
            $this->get(route($route, ['sort' => 'name', 'direction' => 'asc']))->assertForbidden();
        }
        Http::assertNothingSent();
    }
}
