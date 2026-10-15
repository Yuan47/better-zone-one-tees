@extends('layout')
@section('title', 'Store overview')
@section('content')
<div class="section-heading">
    <div><span class="eyebrow">YOUR STORE, AT A GLANCE</span><h1>Welcome back, {{ explode(' ', auth()->user()->name)[0] }} <span class="hello-dot">✳</span></h1><p class="muted">Here’s what’s happening at Zone One Tee’s.</p></div>
    <a class="button" href="{{ route('admin.reports.export', $salesChart['custom_range'] ? ['period' => $period, 'start_date' => $salesChart['start_date'], 'end_date' => $salesChart['end_date']] : []) }}">↓ Export sales</a>
</div>
<div class="stat-grid">
    <article class="panel stat"><span>Confirmed Clothing sale <i>↗</i></span><h2>₱{{ number_format($revenue / 100, 2) }}</h2><small>{{ $salesScope }} · paid orders after discounts · excludes delivery</small></article>
    <article class="panel stat"><span>Paid orders <i>▤</i></span><h2>{{ number_format($ordersCount) }}</h2><small>{{ $salesScope }} · Current packing queue: {{ $toPack }} orders</small></article>
    <article class="panel stat"><span>Customers <i>♧</i></span><h2>{{ number_format($customers) }}</h2><small>All time · registered customer accounts</small></article>
    <article class="panel stat"><span>Average order value <i>◇</i></span><h2>₱{{ number_format($ordersCount ? $revenue / $ordersCount / 100 : 0, 2) }}</h2><small>{{ $salesScope }} · confirmed clothing sale ÷ paid orders</small></article>
</div>
<div class="dashboard-grid" id="reports">
    <section class="panel">
        <div class="section-heading"><h3>Sales over time</h3><span class="badge">{{ $salesChart['history'] }}</span></div>
        <form action="{{ route('admin.dashboard') }}#reports" method="get" class="filters sales-range-form" aria-label="Sales chart period and date range">
            <label for="sales-period">Group sales by<select name="period" id="sales-period">
                @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label for="sales-start-date">From<input id="sales-start-date" type="date" name="start_date" value="{{ $salesChart['start_date'] }}" max="{{ $salesChart['today'] }}"></label>
            <label for="sales-end-date">To<input id="sales-end-date" type="date" name="end_date" value="{{ $salesChart['end_date'] }}" max="{{ $salesChart['today'] }}"></label>
            <button class="button small" type="submit">View sales</button>
            <a class="text-link" href="{{ route('admin.dashboard', ['period' => $period]) }}#reports">Clear dates</a>
        </form>
        <p class="muted">{{ $salesChart['custom_range'] ? 'Selected dates: '.$salesChart['history'].' (inclusive).' : 'Showing '.$salesChart['history'].'. Cards and best sellers use all-time paid sales until dates are selected.' }} Paid clothing sales after discounts, excluding delivery · Asia/Manila. {{ $period === 'weekly' ? 'Weeks start Monday. ' : '' }}@if(collect($chart)->contains('current', true))The current {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$period] }} is still in progress.@endif</p>
        <p class="muted sales-range-hint">Enter both dates or leave both blank. Daily: up to 120 days; weekly: up to 366 days; monthly/yearly: up to 10 years and 120 periods.</p>
        <div class="sales-chart-scroll revenue-chart">
            @php($plot = $salesChart['plot'])
            <svg class="sales-line-chart {{ count($chart) <= 12 ? 'compact-chart' : '' }}" width="{{ $plot['width'] }}" height="{{ $plot['height'] }}" viewBox="0 0 {{ $plot['width'] }} {{ $plot['height'] }}" role="img" aria-labelledby="sales-chart-title sales-chart-description">
                <title id="sales-chart-title">{{ ucfirst($period) }} confirmed clothing sales · {{ $salesChart['history'] }}</title>
                <desc id="sales-chart-description">Line chart in Philippine pesos. Total shown: ₱{{ number_format($salesChart['total'] / 100, 2) }} across {{ $salesChart['orders'] }} paid orders. Exact values and dates are available in the table below.</desc>
                @foreach($plot['ticks'] as $tick)
                    <line class="sales-chart-grid" x1="100" y1="{{ $tick['y'] }}" x2="{{ $plot['width'] - 25 }}" y2="{{ $tick['y'] }}" stroke="var(--border)" />
                    <text class="sales-chart-axis" x="90" y="{{ $tick['y'] + 4 }}" text-anchor="end" fill="var(--muted)">₱{{ number_format($tick['amount'] / 100, 2) }}</text>
                @endforeach
                <polyline class="sales-chart-line" points="{{ $plot['line'] }}" fill="none" stroke="var(--oxblood)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                @foreach($chart as $i => $point)
                    <g><title>{{ $point['range'] }}{{ $point['current'] ? ' · in progress' : '' }}: ₱{{ number_format($point['amount'] / 100, 2) }} · {{ $point['orders'] }} paid orders</title><circle class="sales-chart-point" cx="{{ $plot['points'][$i]['x'] }}" cy="{{ $plot['points'][$i]['y'] }}" r="4" fill="var(--rust)" stroke="white" stroke-width="2" /></g>
                    <text class="sales-chart-label" x="{{ $plot['points'][$i]['x'] }}" y="240" text-anchor="middle" fill="var(--muted)">{{ $point['label'] }}{{ $point['current'] ? '*' : '' }}</text>
                @endforeach
            </svg>
        </div>
        <p class="muted chart-scroll-note">Swipe ↔ to see earlier periods.</p>
        @if(!$salesChart['orders'])<p class="empty-note">No verified paid orders in this period yet.</p>@endif
        <details class="forecast-details"><summary>View exact sales amounts</summary><div class="table-scroll"><table><thead><tr><th>Period</th><th>Paid orders</th><th>Confirmed clothing sale</th></tr></thead><tbody>
            @foreach($chart as $point)<tr><td>{{ $point['range'] }}{{ $point['current'] ? ' (in progress)' : '' }}</td><td>{{ $point['orders'] }}</td><td>₱{{ number_format($point['amount'] / 100, 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th>Total shown</th><th>{{ $salesChart['orders'] }}</th><th>₱{{ number_format($salesChart['total'] / 100, 2) }}</th></tr></tfoot></table></div></details>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Best sellers</h3><span class="muted">{{ $salesChart['custom_range'] ? 'Selected dates · units sold' : 'All-time units sold' }}</span></div>
        @forelse($best as $seller)<div class="rank-row"><span class="rank">{{ $loop->iteration }}</span><b>{{ $seller->name }}</b><span>{{ $seller->units }} pcs</span></div>
        @empty<div class="small-empty"><span>◇</span><h3>{{ $salesChart['custom_range'] ? 'No sales in selected dates' : 'Ready for your first sale' }}</h3><p>Best sellers appear after verified payments{{ $salesChart['custom_range'] ? ' within the selected dates' : '' }}.</p></div>@endforelse
    </section>
</div>
<div class="dashboard-grid">
    <section class="panel">
        <div class="section-heading"><h3>Recent orders</h3><a class="text-link" href="{{ route('admin.orders') }}">View all →</a></div>
        <div class="table-scroll"><table><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead><tbody>
            @forelse($recent as $order)<tr><td><a class="text-link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a></td><td>{{ $order->user->name }}</td><td>₱{{ number_format($order->total / 100, 2) }}</td><td><span class="badge">{{ $order->payment_status==='paid' && $order->status!=='cancelled' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->status)) }}</span></td></tr>
            @empty<tr><td colspan="4" class="empty-note">Orders will appear here as customers check out.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Inventory attention</h3><span class="badge">Available stock</span></div>
        <p class="muted">Out-of-stock variants first, then lowest available quantities. {{ number_format($lowStockCount) }} variants at or below their reorder threshold.</p>
        @forelse($lowStock as $v)<div class="rank-row"><b>{{ $v->product->name }}<small>{{ $v->size }} / {{ $v->color }}</small></b><span class="badge warning">{{ $v->available() }} left</span></div>
        @empty<div class="small-empty"><span>✓</span><h3>All stocked up</h3><p>No variants are below their reorder threshold.</p></div>@endforelse
        <a class="text-link" href="{{ route('admin.inventory-attention') }}">View all {{ number_format($lowStockCount) }} shortages →</a>
    </section>
</div>
<section class="panel"><div class="section-heading"><div><h3>Sales &amp; Growth Forecast</h3><p class="muted">Explore historical demand, growth estimates, and suggested reorder quantities.</p></div><a class="button small" href="{{ route('admin.forecast') }}">Open forecast →</a></div></section>
@endsection
