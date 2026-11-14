@extends('layout')
@section('title', 'Store overview')
@section('content')
<div class="store-dashboard" data-report-live data-report-refresh-url="{{ $reportRefreshUrl }}" data-report-login-url="{{ route('admin.login') }}" data-report-interval="15000">
<div class="section-heading dashboard-heading">
    <div><h1>{{ auth()->user()->role === 'admin' ? 'Store dashboard' : 'Staff dashboard' }}</h1><p class="muted">{{ auth()->user()->role === 'admin' ? 'Review sales and the orders that need attention.' : 'Prepare paid orders and keep deliveries moving.' }}</p></div>
    <div class="dashboard-heading-actions"><a class="button dark" href="{{ route('admin.walk-in.create') }}">＋ Walk-in sale</a><a class="button" href="{{ route('admin.reports.export', $salesChart['custom_range'] ? ['period' => $period, 'start_date' => $salesChart['start_date'], 'end_date' => $salesChart['end_date']] : []) }}">↓ Export sales</a></div>
</div>
<section data-report-region="needs-attention" class="panel dashboard-task-panel" aria-labelledby="dashboard-task-heading">
    <div class="section-heading"><h2 id="dashboard-task-heading">Needs attention</h2><a class="text-link" href="{{ route('admin.operations') }}">Orders &amp; delivery →</a></div>
    <div class="dashboard-task-list">
        @foreach($taskCards as $task)
            <a class="dashboard-task task-{{ $task['tone'] }}" href="{{ $task['url'] }}"><strong>{{ number_format($task['count']) }}</strong><span>{{ $task['label'] }}</span></a>
        @endforeach
    </div>
</section>
<div data-report-region="sales-cards" class="stat-grid dashboard-metrics" aria-label="Store summary">
    <a class="panel stat dashboard-stat-link" href="#reports"><span>Confirmed Clothing sale <i aria-hidden="true">↗</i></span><h2>₱{{ number_format($revenue / 100, 2) }}</h2><small>{{ $salesScope }} · paid orders after discounts · excludes delivery</small></a>
    <a class="panel stat dashboard-stat-link" href="#reports"><span>Paid orders <i aria-hidden="true">▤</i></span><h2>{{ number_format($ordersCount) }}</h2><small>{{ $salesScope }} · Current packing queue: {{ $toPack }} orders</small></a>
    @if(auth()->user()->role === 'admin')
        <a class="panel stat dashboard-stat-link" href="{{ route('admin.customers') }}"><span>Customers <i aria-hidden="true">♧</i></span><h2>{{ number_format($customers) }}</h2><small>All time · registered customer accounts</small></a>
    @else
        <article class="panel stat"><span>Customers <i aria-hidden="true">♧</i></span><h2>{{ number_format($customers) }}</h2><small>All time · registered customer accounts</small></article>
    @endif
    <article class="panel stat"><span>Average order value <i>◇</i></span><h2>₱{{ number_format($ordersCount ? $revenue / $ordersCount / 100 : 0, 2) }}</h2><small>{{ $salesScope }} · confirmed clothing sale ÷ paid orders</small></article>
</div>
<div data-report-region="current-cards" class="stat-grid dashboard-metrics dashboard-current-metrics" aria-label="Current delivery and payment summary">
    <a class="panel stat dashboard-stat-link" href="{{ $widgetLinks['on_delivery'] }}"><span>On delivery <i aria-hidden="true">↗</i></span><h2>{{ number_format($widgetStats['on_delivery']) }}</h2><small>To Ship · all active courier orders</small></a>
    <a class="panel stat dashboard-stat-link" href="{{ $widgetLinks['outstanding'] }}"><span>Customers with balances <i aria-hidden="true">↗</i></span><h2>{{ number_format($widgetStats['outstanding_customers']) }}</h2><small>Registered customers with a balance
        @if($widgetStats['guest_outstanding_invoices'])
            · {{ number_format($widgetStats['guest_outstanding_invoices']) }} guest invoices tracked separately
        @endif
    </small></a>
    <a class="panel stat dashboard-stat-link" href="{{ $widgetLinks['outstanding'] }}"><span>Outstanding payment <i aria-hidden="true">↗</i></span><h2>₱{{ number_format($widgetStats['outstanding_payment'] / 100, 2) }}</h2><small>Total still owed · includes guest invoices</small></a>
    <a class="panel stat dashboard-stat-link" href="{{ $widgetLinks['month_sales'] }}"><span>Total sales this month <i aria-hidden="true">↗</i></span><h2>₱{{ number_format($widgetStats['month_sales'] / 100, 2) }}</h2><small>{{ $widgetStats['month_label'] }} · confirmed clothing sales</small></a>
</div>
<p class="muted dashboard-snapshot-note">Delivery and balances show the current position across all dates. This month’s sales use the current calendar month in Asia/Manila. The sales date filters below apply to the first sales cards and charts.</p>
<details class="dashboard-help"><summary>How the summary figures are calculated</summary>
<p class="muted">Confirmed clothing sales include fully paid online and walk-in invoices, after discounts and before refunds. Average order value uses this same clothing amount before refunds. <a class="text-link" href="{{ route('admin.operations') }}">View outstanding invoices in Operations →</a></p>
</details>
@include('admin.partials.sales-reports', ['showChannels' => true])
@include('admin.partials.top-items-chart')
<div data-report-region="delivery-methods" class="dashboard-grid dashboard-secondary-charts" id="invoice-status">
        <section class="panel report-count-panel dashboard-method-panel" aria-labelledby="invoice-method-heading">
            <div class="section-heading"><div><h3 id="invoice-method-heading">Confirmed paid orders by delivery method</h3><p class="muted">{{ $invoiceScope }} · {{ number_format($methodTotal) }} fully paid pickup / Lalamove orders · order placement dates</p></div></div>
            <ul class="report-count-chart" aria-label="Fully paid order delivery method counts and order links">
                @foreach($methodRows as $row)
                    <li class="report-count-row">
                        <div class="report-count-label"><a class="text-link" href="{{ $row['url'] }}"><i class="report-count-key" style="background:{{ $row['color'] }}" aria-hidden="true"></i>{{ $row['label'] }} →</a><strong>{{ number_format($row['count']) }} <small>{{ $row['count'] === 1 ? 'order' : 'orders' }}</small></strong></div>
                        <div class="report-count-track" aria-hidden="true"><span style="width:{{ $row['percentage'] }}%;background:{{ $row['color'] }}"></span></div>
                        <p class="report-count-share">{{ number_format($row['percentage'], 1) }}% of paid pickup / Lalamove orders</p>
                    </li>
                @endforeach
            </ul>
            @if(!$methodTotal)<p class="empty-note">No fully paid pickup or Lalamove orders in these placed dates.</p>@endif
            @if($otherMethodCount)<p class="muted">{{ number_format($otherMethodCount) }} fully paid orders use another delivery method and are excluded from this comparison.</p>@endif
            <p class="muted">Includes fully paid online and walk-in orders at every fulfillment stage.</p>
        </section>

</div>
<div data-report-region="recent-inventory" class="dashboard-grid">
    <section class="panel">
        <div class="section-heading"><h3>Recent orders</h3><a class="text-link" href="{{ route('admin.orders') }}">View all →</a></div>
        <div class="table-scroll"><table data-sortable><thead><tr><th data-sort-type="text">Order</th><th data-sort-type="text">Customer</th><th data-sort-type="number">Total</th><th data-sort-type="text">Status</th></tr></thead><tbody>
            @forelse($recent as $order)<tr data-sort-row><td data-sort-value="{{ $order->number }}"><a class="text-link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a><small>{{ $order->sales_channel === 'walk_in' ? 'Walk-in' : 'Online' }}</small></td><td data-sort-value="{{ $order->user?->name ?? $order->recipient }}">{{ $order->user?->name ?? $order->recipient }}@if(!$order->user)<small>Guest invoice</small>@endif</td><td data-sort-value="{{ $order->total }}">₱{{ number_format($order->total / 100, 2) }}</td><td data-sort-value="{{ $order->status === 'cancelled' ? 'Cancelled' : ($order->payment_status === 'paid' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->payment_status))) }}"><span class="badge {{ $order->customerDeliveryBadgeClass() }}">{{ $order->status === 'cancelled' ? 'Cancelled' : ($order->payment_status === 'paid' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->payment_status))) }}</span></td></tr>
            @empty<tr><td colspan="4" class="empty-note">Orders will appear here as customers check out.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Inventory attention</h3><span class="badge">Available stock</span></div>
        <p class="muted">Out-of-stock variants first, then lowest available quantities. {{ number_format($lowStockCount) }} variants at or below their reorder threshold.</p>
        @include('admin.partials.inventory-status-guide')
        @forelse($lowStock as $v)<div class="rank-row inventory-attention-row"><b>{{ $v->product->name }}<small>{{ $v->size }} / {{ $v->color }}</small></b>@include('admin.partials.inventory-status', ['variant' => $v])</div>
        @empty<div class="small-empty"><span>✓</span><h3>All stocked up</h3><p>No variants are below their reorder threshold.</p></div>@endforelse
        <a class="text-link" href="{{ route('admin.inventory-attention') }}">View all {{ number_format($lowStockCount) }} shortages →</a>
    </section>
</div>
<section class="panel"><div class="section-heading"><div><h3>Sales &amp; Growth Forecast</h3><p class="muted">Explore historical demand, growth estimates, and suggested reorder quantities.</p></div><a class="button small" href="{{ route('admin.forecast') }}">Open forecast →</a></div></section>
<section data-report-region="invoice-refunds" class="panel report-count-section dashboard-refunds" aria-labelledby="invoice-refund-heading">
    <div class="section-heading"><div><h2 id="invoice-refund-heading">Paid invoices after refunds</h2><p class="muted">{{ $salesScope }} · invoices selected by payment completion date · includes delivery</p></div></div>
    <div class="invoice-totals">
        <div><span>Original invoice value</span><strong>₱{{ number_format($invoiceSummary['original_invoice'] / 100, 2) }}</strong><small>Clothing after discounts + delivery</small></div>
        <div><span>Confirmed refunds</span><strong>₱{{ number_format($invoiceSummary['confirmed_refunds'] / 100, 2) }}</strong><small>Verified online refunds + recorded cash returns</small></div>
        <div><span>Net invoice value</span><strong>₱{{ number_format($invoiceSummary['net_invoice'] / 100, 2) }}</strong><small>Original invoice value − confirmed refunds</small></div>
    </div>
    <p class="muted">Refunds known as of {{ \Carbon\CarbonImmutable::parse($invoiceSummary['as_of'])->format('M j, Y, g:i A') }} (Asia/Manila). A later refund updates the original paid invoice’s net value, including when viewing an earlier payment period. Requested, approved and processing refunds are excluded.</p>
    <p class="muted">New return inspections record returned quantities and clothing/delivery allocations. Historical refunds may have no clothing/delivery allocation. The clothing chart, average order value and original units sold deliberately show paid sales before refunds or returns; net invoice value above shows confirmed refunds separately and is not profit. <a class="text-link" href="{{ route('admin.operations') }}">Operations shows refunds by confirmation date →</a></p>
</section>
</div>
@endsection
