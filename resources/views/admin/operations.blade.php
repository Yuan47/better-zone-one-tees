@extends('layout')
@section('title', 'Operations overview')
@section('crumb', 'Operations')
@section('content')
<div class="operations-page">
<div class="section-heading">
    <div><span class="eyebrow">ORDERS, DELIVERY & REFUNDS</span><h1>Operations overview</h1><p class="muted">Follow confirmed orders from packing to handover, and keep refund requests visible.</p></div>
    <div class="operations-heading-actions"><a class="button dark" href="#delivery-status">Delivery charts ↓</a><a class="button" href="{{ route('admin.orders', $filters) }}">Open orders →</a></div>
</div>
<form method="get" action="{{ route('admin.operations') }}" class="filters operations-filters" aria-label="Operations date range">
    <label>Orders placed from<input type="date" name="start_date" value="{{ $startDate }}" max="{{ $today }}" required></label>
    <label>Through<input type="date" name="end_date" value="{{ $endDate }}" max="{{ $today }}" required></label>
    <label>Trend view<select name="period"><option value="daily" @selected($period === 'daily')>Daily</option><option value="monthly" @selected($period === 'monthly')>Monthly</option></select></label>
    <button class="button small">Apply dates</button><a class="text-link" href="{{ route('admin.operations') }}">Last 30 days</a>
</form>
<p class="muted operations-period-note">{{ \Carbon\CarbonImmutable::parse($startDate)->format('M j, Y') }} – {{ \Carbon\CarbonImmutable::parse($endDate)->format('M j, Y') }} · Asia/Manila · up to 366 days. Current order status as of {{ $now->format('M j, Y · g:i A') }}.@if($endDate === $today) Today is still in progress.@endif</p>
@include('admin.partials.delivery-status')
<div class="stat-grid operations-stat-grid">
    <article class="panel stat operations-card"><span>Confirmed paid orders</span><h2>{{ number_format($stats['paid_orders']) }}</h2><small>{{ number_format($stats['paid_pieces']) }} clothing pieces · orders placed in this range</small><a class="text-link" href="{{ route('admin.orders', $filters + ['payment_status' => 'paid']) }}">View paid orders →</a></article>
    <article class="panel stat operations-card"><span>Store pickup</span><h2>{{ number_format($stats['pickup']) }}</h2><small>Paid orders · all current fulfillment stages</small></article>
    <article class="panel stat operations-card"><span>Lalamove orders</span><h2>{{ number_format($stats['lalamove']) }}</h2><small>Paid orders · includes those still being packed</small></article>
    <article class="panel stat operations-card"><span>Verified / recorded refunds</span><h2>{{ $stats['confirmed_refunds'] ? '₱'.number_format($stats['refund_amount'] / 100, 2) : '—' }}</h2><small>{{ $stats['confirmed_refunds'] ? number_format($stats['provider_refunds']).' PayMongo-confirmed · '.number_format($stats['cash_refunds']).' recorded cash refunds' : 'No verified or recorded refunds in this range' }}</small><a class="text-link" href="{{ route('admin.refunds.index') }}">Open refund queue →</a></article>
</div>
@if($stats['other_delivery'])<p class="muted">{{ number_format($stats['other_delivery']) }} paid orders use another delivery method.</p>@endif
<section class="report-count-section" aria-labelledby="operations-payment-heading">
    <div class="section-heading"><div><h2 id="operations-payment-heading">Invoice payments &amp; delivery methods</h2><p class="muted">Current payment status of orders placed from {{ $startDate }} through {{ $endDate }}. Cancelled and released invoices are excluded from these comparisons.</p></div></div>
    <div class="report-count-grid">
        <section class="panel report-count-panel" aria-labelledby="operations-payment-chart-heading">
            <div class="section-heading"><div><h3 id="operations-payment-chart-heading">Fully paid vs outstanding invoices</h3><p class="muted">{{ number_format($paymentTotal) }} invoices · outstanding means partially paid or unpaid</p></div></div>
            <ul class="report-count-chart" aria-label="Invoice payment status counts and order links">
                @foreach($paymentRows as $row)
                    <li class="report-count-row">
                        <div class="report-count-label"><a class="text-link" href="{{ $row['url'] }}"><i class="report-count-key" style="background:{{ $row['color'] }}" aria-hidden="true"></i>{{ $row['label'] }} →</a><strong>{{ number_format($row['count']) }} <small>{{ $row['count'] === 1 ? 'invoice' : 'invoices' }}</small></strong></div>
                        <div class="report-count-track" aria-hidden="true"><span style="width:{{ $row['percentage'] }}%;background:{{ $row['color'] }}"></span></div>
                        <p class="report-count-share">{{ number_format($row['percentage'], 1) }}% of invoices shown</p>
                    </li>
                @endforeach
            </ul>
            @if(!$paymentTotal)<p class="empty-note">No paid, partially paid or unpaid invoices in these placed dates.</p>@endif
            @if($otherPaymentCount)<p class="muted">{{ number_format($otherPaymentCount) }} invoices with other unconfirmed payment statuses are excluded from this comparison.</p>@endif
            <p class="muted">Deposits remain outstanding until full settlement. They are excluded from confirmed clothing sales and delivery counts.</p>
        </section>
        <section class="panel report-count-panel" aria-labelledby="operations-method-chart-heading">
            <div class="section-heading"><div><h3 id="operations-method-chart-heading">Confirmed paid orders by delivery method</h3><p class="muted">{{ number_format($methodTotal) }} fully paid pickup / Lalamove orders</p></div></div>
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
            <p class="muted">Paid online and walk-in orders are included. Cancelled paid invoices remain in the financial cards above for reconciliation.</p>
        </section>
    </div>
</section>
<section aria-labelledby="fulfillment-heading">
    <div class="section-heading"><div><h2 id="fulfillment-heading">Current fulfillment status</h2><p class="muted">Only paid, non-cancelled orders placed in the selected dates. Each order appears in exactly one stage. Pickup orders in Ready for delivery are ready for collection.</p></div></div>
    @if($stats['cancelled_paid'])<p class="muted">{{ number_format($stats['cancelled_paid']) }} cancelled paid orders are excluded from fulfillment stages and remain in paid-order totals for reconciliation.</p>@endif
    <div class="stat-grid operations-stat-grid">
        @foreach($statusRows as $row)
            <a class="panel stat operations-card operations-status-card delivery-stage-{{ $row['status'] }}" href="{{ $row['url'] }}" aria-label="{{ $row['label'] }}: {{ $row['count'] }} paid orders; open filtered orders"><span>{{ $row['label'] }}</span><h2>{{ number_format($row['count']) }}</h2><small>View orders →</small></a>
        @endforeach
    </div>
</section>
<div class="dashboard-grid operations-support-grid">
    <section class="panel">
        <div class="section-heading"><h3>Fulfillment breakdown</h3><span class="badge">{{ number_format($stats['fulfillment_orders']) }} paid, non-cancelled orders</span></div>
        <div class="operations-status-chart" role="img" aria-label="Current fulfillment stage of paid, non-cancelled orders placed from {{ $startDate }} through {{ $endDate }}. Exact counts and percentages appear in the table below.">
            @foreach($statusRows as $row)
                <div class="operations-status-row" aria-hidden="true"><span>{{ $row['label'] }}</span><div class="operations-status-track"><div class="operations-status-fill" style="width:{{ $row['percentage'] }}%;background:{{ $row['color'] }}"></div></div><b>{{ number_format($row['count']) }}</b></div>
            @endforeach
        </div>
        @if(!$stats['paid_orders'])<p class="empty-note">No confirmed paid orders were placed in these dates.</p>@endif
        @if($stats['paid_orders'] && !$stats['fulfillment_orders'])<p class="empty-note">No paid orders are eligible for fulfillment in these dates.</p>@endif
        <details class="forecast-details"><summary>View exact fulfillment counts</summary><div class="table-scroll"><table><caption class="sr-only">Current stage of paid, non-cancelled orders placed in the selected range</caption><thead><tr><th>Stage</th><th>Orders</th><th>Share of fulfillment orders</th></tr></thead><tbody>
            @foreach($statusRows as $row)<tr><td>{{ $row['label'] }}</td><td>{{ number_format($row['count']) }}</td><td>{{ number_format($row['percentage'], 1) }}%</td></tr>@endforeach
        </tbody><tfoot><tr><th>Total fulfillment orders</th><th>{{ number_format($stats['fulfillment_orders']) }}</th><th>{{ $stats['fulfillment_orders'] ? '100%' : '0%' }}</th></tr></tfoot></table></div></details>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Payment & refund queues</h3></div>
        <div class="rank-row"><b>Awaiting payment<small>Unpaid orders placed in range · excludes cancellations</small></b><span>{{ number_format($stats['unpaid_pending']) }}</span></div>
        <div class="rank-row"><b>Partially paid invoices<small>Deposits do not count as confirmed clothing sales or delivery orders</small></b><span>{{ number_format($stats['partially_paid']) }}</span></div>
        <div class="rank-row"><b>Outstanding invoices<small>{{ number_format($stats['outstanding_invoices']) }} pending / partially paid invoices placed in these dates · excludes cancellations</small></b><span>₱{{ number_format($stats['outstanding_amount']/100, 2) }}</span></div>
        <div class="rank-row"><b>Received deposits on these invoices<small>Current deposits on partially paid invoices placed in these dates; not receipts grouped by payment date</small></b><span>₱{{ number_format($stats['received_deposits']/100, 2) }}</span></div>
        <p><a class="text-link" href="{{ route('admin.orders', $filters + ['outstanding' => 1]) }}">View outstanding invoices →</a> · <a class="text-link" href="{{ route('admin.orders', $filters + ['overdue' => 1]) }}">{{ number_format($stats['overdue_invoices']) }} overdue invoices →</a></p>
        <div class="rank-row"><b>Cancelled unconfirmed invoices<small>Excluded from every confirmed paid-order card and chart</small></b><span>{{ number_format($stats['cancelled_unpaid']) }}</span></div>
        <div class="rank-row"><b>Open refund requests<small>Requested, approved or provider processing · request placed in range</small></b><span>{{ number_format($stats['open_refund_requests']) }}</span></div>
        <div class="rank-row"><b>PayMongo-confirmed refunds</b><span>₱{{ number_format($stats['provider_refund_amount']/100, 2) }}</span></div>
        <div class="rank-row"><b>Recorded cash refunds<small>Owner receipt and audit required · not a PayMongo confirmation</small></b><span>₱{{ number_format($stats['cash_refund_amount']/100, 2) }}</span></div>
        <p class="muted">Refund requests are available after delivery is confirmed. Online orders require the customer's receipt confirmation and review.</p>
        <a class="text-link" href="{{ route('admin.refunds.index') }}">View refund request queue →</a>
        @if($stats['other_unpaid'])<div class="rank-row"><b>Other unconfirmed payments</b><span>{{ number_format($stats['other_unpaid']) }}</span></div>@endif
        <p class="muted">{{ number_format($stats['placed_orders']) }} total orders were placed in this range, including paid, partially paid, unpaid and cancelled orders. Pending and partial payments are excluded from confirmed order counts. Fully settled walk-in cash invoices are included.</p>
        <p class="muted">Provider-confirmed and owner-recorded cash refunds are separate financial events. An order with a refund remains in its current fulfillment status, and may have been placed before this range.</p>
        <p class="muted">Refund amounts are gross recorded amounts and may include delivery fees. They do not automatically reduce Confirmed Clothing sale because a clothing/delivery allocation has not been recorded.</p>
    </section>
</div>
<section class="panel operations-trend-panel">
    <div class="section-heading"><div><h3>Orders & refund confirmations over time</h3><p class="muted">{{ ucfirst($period) }} counts · independent series; do not add them together</p></div></div>
    <div class="operations-legend"><span><i class="operations-order-key" aria-hidden="true"></i>Paid orders · date placed</span><span><i class="operations-refund-key" aria-hidden="true"></i>Verified / recorded refunds · date confirmed or recorded</span></div>
    @php($chartMax = max(4, (int) (ceil(max(array_merge(array_column($chart, 'orders'), array_column($chart, 'refunds'))) / 4) * 4)))
    @php($points = count($chart))
    @php($orderPoints = [])
    @php($refundPoints = [])
    @foreach($chart as $i => $point)
        @php($x = $points === 1 ? 480 : 60 + $i / ($points - 1) * 840)
        @php($orderPoints[] = number_format($x, 2, '.', '').','.number_format(245 - $point['orders'] / $chartMax * 190, 2, '.', ''))
        @php($refundPoints[] = number_format($x, 2, '.', '').','.number_format(245 - $point['refunds'] / $chartMax * 190, 2, '.', ''))
    @endforeach
    <div class="operations-chart-scroll"><svg class="operations-chart" width="960" height="300" viewBox="0 0 960 300" role="img" aria-labelledby="operations-trend-title operations-trend-description">
        <title id="operations-trend-title">{{ ucfirst($period) }} paid order and verified / recorded refund counts from {{ $startDate }} through {{ $endDate }}</title>
        <desc id="operations-trend-description">{{ $stats['paid_orders'] }} paid orders grouped by the date they were placed, and {{ $stats['confirmed_refunds'] }} provider-confirmed or owner-recorded cash refund events grouped by confirmation or recording date. The series overlap and are not order-status categories. Exact values are in the following table.</desc>
        @for($tick = 0; $tick <= 4; $tick++)<line x1="60" y1="{{ 245 - $tick * 47.5 }}" x2="900" y2="{{ 245 - $tick * 47.5 }}" stroke="#e4d9c8"/><text x="46" y="{{ 250 - $tick * 47.5 }}" text-anchor="end" font-size="13" fill="#735b52">{{ number_format($chartMax / 4 * $tick) }}</text>@endfor
        <polyline points="{{ implode(' ', $orderPoints) }}" fill="none" stroke="#941B0C" stroke-width="3"/>
        <polyline points="{{ implode(' ', $refundPoints) }}" fill="none" stroke="#BC3908" stroke-width="3" stroke-dasharray="6 4"/>
        @foreach($chart as $i => $point)
            @php($x = $points === 1 ? 480 : 60 + $i / ($points - 1) * 840)
            @if($points <= 62 || $point['orders'] || $point['refunds'])<circle cx="{{ $x }}" cy="{{ 245 - $point['orders'] / $chartMax * 190 }}" r="3" fill="#941B0C"><title>{{ $point['range'] }}: {{ $point['orders'] }} paid orders placed</title></circle><circle cx="{{ $x }}" cy="{{ 245 - $point['refunds'] / $chartMax * 190 }}" r="3" fill="#BC3908"><title>{{ $point['range'] }}: {{ $point['refunds'] }} verified / recorded refund events</title></circle>@endif
            @if($i === 0 || $i === $points - 1 || ($i % max(1, (int) ceil($points / 6)) === 0 && $i < $points - 2))<text x="{{ $x }}" y="275" text-anchor="{{ $i === 0 ? 'start' : ($i === $points - 1 ? 'end' : 'middle') }}" font-size="13" fill="#735b52">{{ $point['label'] }}</text>@endif
        @endforeach
    </svg></div>
    @if(!$stats['paid_orders'] && !$stats['confirmed_refunds'])<p class="empty-note">No paid orders or verified / recorded refunds in the selected date range.</p>@endif
    <details class="forecast-details"><summary>View exact order and refund figures</summary><div class="table-scroll"><table><caption class="sr-only">{{ ucfirst($period) }} counts and gross provider-confirmed or owner-recorded cash refund amounts</caption><thead><tr><th>Period</th><th>Paid orders placed</th><th>Verified / recorded refund events</th><th>Gross refunds</th></tr></thead><tbody>
        @foreach($chart as $point)<tr><td>{{ $point['range'] }}</td><td>{{ number_format($point['orders']) }}</td><td>{{ number_format($point['refunds']) }}</td><td>₱{{ number_format($point['refund_amount'] / 100, 2) }}</td></tr>@endforeach
    </tbody><tfoot><tr><th>Totals for selected dates</th><th>{{ number_format($stats['paid_orders']) }}</th><th>{{ number_format($stats['confirmed_refunds']) }}</th><th>₱{{ number_format($stats['refund_amount'] / 100, 2) }}</th></tr></tfoot></table></div></details>
</section>
</div>
@endsection
