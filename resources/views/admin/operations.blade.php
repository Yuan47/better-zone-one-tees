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
<div class="stat-grid operations-stat-grid">
    <article class="panel stat operations-card"><span>Confirmed paid orders</span><h2>{{ number_format($stats['paid_orders']) }}</h2><small>{{ number_format($stats['paid_pieces']) }} clothing pieces · orders placed in this range</small><a class="text-link" href="{{ route('admin.orders', $filters + ['payment_status' => 'paid']) }}">View paid orders →</a></article>
    <article class="panel stat operations-card"><span>Store pickup</span><h2>{{ number_format($stats['pickup']) }}</h2><small>Paid orders · all current fulfillment stages</small></article>
    <article class="panel stat operations-card"><span>Lalamove orders</span><h2>{{ number_format($stats['lalamove']) }}</h2><small>Paid orders · includes those still being packed</small></article>
    <article class="panel stat operations-card"><span>Confirmed refunds</span><h2>{{ $stats['confirmed_refunds'] ? '₱'.number_format($stats['refund_amount'] / 100, 2) : '—' }}</h2><small>{{ $stats['confirmed_refunds'] ? number_format($stats['confirmed_refunds']).' provider-confirmed refund events in this range' : 'No provider-confirmed refunds in this range' }}</small><a class="text-link" href="{{ route('admin.refunds.index') }}">Open refund queue →</a></article>
</div>
@if($stats['other_delivery'])<p class="muted">{{ number_format($stats['other_delivery']) }} paid orders use another delivery method.</p>@endif
@include('admin.partials.delivery-status')
<section aria-labelledby="fulfillment-heading">
    <div class="section-heading"><div><h2 id="fulfillment-heading">Current fulfillment status</h2><p class="muted">Only paid orders placed in the selected dates. Each order appears in exactly one status.</p></div></div>
    <div class="stat-grid operations-stat-grid">
        @foreach($statusRows as $row)
            @if($row['status'] !== 'other' || $row['count'])
                @if($row['url'])<a class="panel stat operations-card operations-status-card" href="{{ $row['url'] }}" aria-label="{{ $row['label'] }}: {{ $row['count'] }} paid orders; open filtered orders"><span>{{ $row['label'] }}</span><h2>{{ number_format($row['count']) }}</h2><small>View orders →</small></a>
                @else<article class="panel stat operations-card operations-status-card"><span>{{ $row['label'] }}</span><h2>{{ number_format($row['count']) }}</h2><small>Paid orders outside the listed fulfillment stages</small></article>@endif
            @endif
        @endforeach
    </div>
</section>
<div class="dashboard-grid operations-support-grid">
    <section class="panel">
        <div class="section-heading"><h3>Fulfillment breakdown</h3><span class="badge">{{ number_format($stats['paid_orders']) }} paid orders</span></div>
        <div class="operations-status-chart" role="img" aria-label="Current fulfillment status of paid orders placed from {{ $startDate }} through {{ $endDate }}. Exact counts and percentages appear in the table below.">
            @foreach($statusRows as $row)
                @if($row['status'] !== 'other' || $row['count'])<div class="operations-status-row" aria-hidden="true"><span>{{ $row['label'] }}</span><div class="operations-status-track"><div class="operations-status-fill" style="width:{{ $row['percentage'] }}%"></div></div><b>{{ number_format($row['count']) }}</b></div>@endif
            @endforeach
        </div>
        @if(!$stats['paid_orders'])<p class="empty-note">No confirmed paid orders were placed in these dates.</p>@endif
        <details class="forecast-details"><summary>View exact fulfillment counts</summary><div class="table-scroll"><table><caption class="sr-only">Current status of paid orders placed in the selected range</caption><thead><tr><th>Status</th><th>Orders</th><th>Share of paid orders</th></tr></thead><tbody>
            @foreach($statusRows as $row)<tr><td>{{ $row['label'] }}</td><td>{{ number_format($row['count']) }}</td><td>{{ number_format($row['percentage'], 1) }}%</td></tr>@endforeach
        </tbody><tfoot><tr><th>Total paid orders</th><th>{{ number_format($stats['paid_orders']) }}</th><th>{{ $stats['paid_orders'] ? '100%' : '0%' }}</th></tr></tfoot></table></div></details>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Payment & refund queues</h3></div>
        <div class="rank-row"><b>Awaiting payment<small>Unpaid orders placed in range · excludes cancellations</small></b><span>{{ number_format($stats['unpaid_pending']) }}</span></div>
        <div class="rank-row"><b>Cancelled unpaid orders<small>Excluded from every paid-order card and chart</small></b><span>{{ number_format($stats['cancelled_unpaid']) }}</span></div>
        <div class="rank-row"><b>Open refund requests<small>Requested, approved or provider processing · request placed in range</small></b><span>{{ number_format($stats['open_refund_requests']) }}</span></div>
        <a class="text-link" href="{{ route('admin.refunds.index') }}">View refund request queue →</a>
        @if($stats['other_unpaid'])<div class="rank-row"><b>Other unconfirmed payments</b><span>{{ number_format($stats['other_unpaid']) }}</span></div>@endif
        <p class="muted">{{ number_format($stats['placed_orders']) }} total orders were placed in this range, including paid, unpaid and cancelled orders. Pending payments are excluded from confirmed order counts.</p>
        <p class="muted">Refund confirmations are separate financial events. An order with a refund remains in its current fulfillment status, and may have been placed before this range.</p>
        <p class="muted">Confirmed refund amounts are gross provider amounts and may include delivery fees. They do not automatically reduce Confirmed Clothing sale because a clothing/delivery allocation has not been recorded.</p>
    </section>
</div>
<section class="panel operations-trend-panel">
    <div class="section-heading"><div><h3>Orders & refund confirmations over time</h3><p class="muted">{{ ucfirst($period) }} counts · independent series; do not add them together</p></div></div>
    <div class="operations-legend"><span><i class="operations-order-key" aria-hidden="true"></i>Paid orders · date placed</span><span><i class="operations-refund-key" aria-hidden="true"></i>Confirmed refunds · date confirmed</span></div>
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
        <title id="operations-trend-title">{{ ucfirst($period) }} paid order and confirmed refund counts from {{ $startDate }} through {{ $endDate }}</title>
        <desc id="operations-trend-description">{{ $stats['paid_orders'] }} paid orders grouped by the date they were placed, and {{ $stats['confirmed_refunds'] }} provider-confirmed refund events grouped by confirmation date. The series overlap and are not order-status categories. Exact values are in the following table.</desc>
        @for($tick = 0; $tick <= 4; $tick++)<line x1="60" y1="{{ 245 - $tick * 47.5 }}" x2="900" y2="{{ 245 - $tick * 47.5 }}" stroke="#e4d9c8"/><text x="46" y="{{ 250 - $tick * 47.5 }}" text-anchor="end" font-size="13" fill="#735b52">{{ number_format($chartMax / 4 * $tick) }}</text>@endfor
        <polyline points="{{ implode(' ', $orderPoints) }}" fill="none" stroke="#941B0C" stroke-width="3"/>
        <polyline points="{{ implode(' ', $refundPoints) }}" fill="none" stroke="#BC3908" stroke-width="3" stroke-dasharray="6 4"/>
        @foreach($chart as $i => $point)
            @php($x = $points === 1 ? 480 : 60 + $i / ($points - 1) * 840)
            @if($points <= 62 || $point['orders'] || $point['refunds'])<circle cx="{{ $x }}" cy="{{ 245 - $point['orders'] / $chartMax * 190 }}" r="3" fill="#941B0C"><title>{{ $point['range'] }}: {{ $point['orders'] }} paid orders placed</title></circle><circle cx="{{ $x }}" cy="{{ 245 - $point['refunds'] / $chartMax * 190 }}" r="3" fill="#BC3908"><title>{{ $point['range'] }}: {{ $point['refunds'] }} confirmed refund events</title></circle>@endif
            @if($i === 0 || $i === $points - 1 || ($i % max(1, (int) ceil($points / 6)) === 0 && $i < $points - 2))<text x="{{ $x }}" y="275" text-anchor="{{ $i === 0 ? 'start' : ($i === $points - 1 ? 'end' : 'middle') }}" font-size="13" fill="#735b52">{{ $point['label'] }}</text>@endif
        @endforeach
    </svg></div>
    @if(!$stats['paid_orders'] && !$stats['confirmed_refunds'])<p class="empty-note">No paid orders or provider-confirmed refunds in the selected date range.</p>@endif
    <details class="forecast-details"><summary>View exact order and refund figures</summary><div class="table-scroll"><table><caption class="sr-only">{{ ucfirst($period) }} counts and actual provider-confirmed gross refund amounts</caption><thead><tr><th>Period</th><th>Paid orders placed</th><th>Confirmed refund events</th><th>Gross confirmed refunds</th></tr></thead><tbody>
        @foreach($chart as $point)<tr><td>{{ $point['range'] }}</td><td>{{ number_format($point['orders']) }}</td><td>{{ number_format($point['refunds']) }}</td><td>₱{{ number_format($point['refund_amount'] / 100, 2) }}</td></tr>@endforeach
    </tbody><tfoot><tr><th>Totals for selected dates</th><th>{{ number_format($stats['paid_orders']) }}</th><th>{{ number_format($stats['confirmed_refunds']) }}</th><th>₱{{ number_format($stats['refund_amount'] / 100, 2) }}</th></tr></tfoot></table></div></details>
</section>
</div>
@endsection
