@extends('layout')
@section('title', 'Store overview')
@section('content')
<div class="section-heading">
    <div><span class="eyebrow">YOUR STORE, AT A GLANCE</span><h1>Welcome back, {{ explode(' ', auth()->user()->name)[0] }} <span class="hello-dot">✳</span></h1><p class="muted">Here’s what’s happening at Zone One Tee’s.</p></div>
    <a class="button" href="{{ route('admin.reports.export') }}">↓ Export sales</a>
</div>
<div class="stat-grid">
    <article class="panel stat"><span>Confirmed Clothing sale <i>↗</i></span><h2>₱{{ number_format($revenue / 100, 2) }}</h2><small>All time · paid orders after discounts · excludes delivery</small></article>
    <article class="panel stat"><span>Paid orders <i>▤</i></span><h2>{{ number_format($ordersCount) }}</h2><small>All time · {{ $toPack }} waiting to be packed</small></article>
    <article class="panel stat"><span>Customers <i>♧</i></span><h2>{{ number_format($customers) }}</h2><small>Registered customer accounts</small></article>
    <article class="panel stat"><span>Average order value <i>◇</i></span><h2>₱{{ number_format($ordersCount ? $revenue / $ordersCount / 100 : 0, 2) }}</h2><small>All time · confirmed clothing sale ÷ paid orders</small></article>
</div>
<div class="dashboard-grid" id="reports">
    <section class="panel">
        <div class="section-heading"><h3>Sales over time</h3><span class="badge">{{ $salesChart['history'] }}</span></div>
        <form action="{{ route('admin.dashboard') }}#reports" method="get" class="filters" aria-label="Sales chart period">
            <label for="sales-period" class="sr-only">View sales by</label>
            <select name="period" id="sales-period">
                @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="button small" type="submit">View sales</button>
        </form>
        <p class="muted">Paid clothing sales after discounts, excluding delivery · Asia/Manila. {{ $period === 'weekly' ? 'Weeks start Monday. ' : '' }}The current {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$period] }} is still in progress.</p>
        <div class="revenue-chart" role="img" aria-label="{{ ucfirst($period) }} confirmed clothing sales in Philippine pesos">
            @php($max = max(1, max(array_column($chart, 'amount'))))
            @foreach($chart as $point)
                <div class="chart-column" title="{{ $point['range'] }}{{ $point['current'] ? ' · in progress' : '' }}: ₱{{ number_format($point['amount'] / 100, 2) }}">
                    <span>₱{{ number_format($point['amount'] / 100, 0) }}</span>
                    <div class="bar-track"><div class="chart-bar" style="height:{{ $point['amount'] / $max * 100 }}%"></div></div>
                    <small>{{ $point['label'] }}{{ $point['current'] ? '*' : '' }}</small>
                </div>
            @endforeach
        </div>
        @if(!$salesChart['orders'])<p class="empty-note">No verified paid orders in this period yet.</p>@endif
        <details class="forecast-details"><summary>View exact sales amounts</summary><div class="table-scroll"><table><thead><tr><th>Period</th><th>Paid orders</th><th>Confirmed clothing sale</th></tr></thead><tbody>
            @foreach($chart as $point)<tr><td>{{ $point['range'] }}{{ $point['current'] ? ' (in progress)' : '' }}</td><td>{{ $point['orders'] }}</td><td>₱{{ number_format($point['amount'] / 100, 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th>Total shown</th><th>{{ $salesChart['orders'] }}</th><th>₱{{ number_format($salesChart['total'] / 100, 2) }}</th></tr></tfoot></table></div></details>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Best sellers</h3><span class="muted">All-time units sold</span></div>
        @forelse($best as $name => $units)<div class="rank-row"><span class="rank">{{ $loop->iteration }}</span><b>{{ $name }}</b><span>{{ $units }} pcs</span></div>
        @empty<div class="small-empty"><span>◇</span><h3>Ready for your first sale</h3><p>Best sellers will appear after verified payments.</p></div>@endforelse
    </section>
</div>
<div class="dashboard-grid">
    <section class="panel">
        <div class="section-heading"><h3>Recent orders</h3><a class="text-link" href="{{ route('admin.orders') }}">View all →</a></div>
        <div class="table-scroll"><table><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead><tbody>
            @forelse($recent as $order)<tr><td><a class="text-link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a></td><td>{{ $order->user->name }}</td><td>₱{{ number_format($order->total / 100, 2) }}</td><td><span class="badge">{{ str_replace('_', ' ', $order->status) }}</span></td></tr>
            @empty<tr><td colspan="4" class="empty-note">Orders will appear here as customers check out.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="section-heading"><h3>Inventory attention</h3><span class="badge">Available stock</span></div>
        @forelse($lowStock as $v)<div class="rank-row"><b>{{ $v->product->name }}<small>{{ $v->size }} / {{ $v->color }}</small></b><span class="badge warning">{{ $v->available() }} left</span></div>
        @empty<div class="small-empty"><span>✓</span><h3>All stocked up</h3><p>No variants are below their reorder threshold.</p></div>@endforelse
    </section>
</div>
<section class="panel"><div class="section-heading"><div><h3>Sales &amp; Growth Forecast</h3><p class="muted">Explore historical demand, growth estimates, and suggested reorder quantities.</p></div><a class="button small" href="{{ route('admin.forecast') }}">Open forecast →</a></div></section>
@endsection
