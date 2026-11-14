    <section data-report-region="sales-chart" class="panel dashboard-sales-panel" aria-labelledby="dashboard-sales-heading">
        <div class="report-total">
            <h2>Total Sales</h2>
            <strong class="report-total-amount" data-report-total>₱{{ number_format($salesChart['total'] / 100, 2) }}</strong>
            <p class="report-total-note">{{ $salesChart['history'] }} · confirmed clothing sales after discounts · before refunds · excludes delivery</p>
        </div>
        <div class="section-heading"><h2 id="dashboard-sales-heading">Sales over time</h2><span class="badge">{{ $salesChart['history'] }}</span></div>
        <div class="sales-period-summary" aria-label="Selected chart totals"><div><span>Paid orders</span><strong>{{ number_format($salesChart['orders']) }}</strong></div><div><span>Average order</span><strong>₱{{ number_format($salesChart['orders'] ? $salesChart['total'] / $salesChart['orders'] / 100 : 0, 2) }}</strong></div></div>
        <div class="sales-chart-scroll revenue-chart" tabindex="0" role="region" aria-label="Sales chart: swipe or use arrow keys to see all dates">
            @php($plot = $salesChart['plot'])
            <svg class="sales-line-chart {{ count($chart) <= 12 ? 'compact-chart' : '' }}" width="{{ $plot['width'] }}" height="{{ $plot['height'] }}" viewBox="0 0 {{ $plot['width'] }} {{ $plot['height'] }}" role="img" aria-labelledby="sales-chart-title sales-chart-description">
                <title id="sales-chart-title">{{ ucfirst($period) }} confirmed clothing sales · {{ $salesChart['history'] }}</title>
                <desc id="sales-chart-description">Line chart in Philippine pesos. Total shown: ₱{{ number_format($salesChart['total'] / 100, 2) }} across {{ $salesChart['orders'] }} paid orders. Exact values and dates are available in the table below.</desc>
                @foreach($plot['ticks'] as $tick)
                    <line class="sales-chart-grid" x1="100" y1="{{ $tick['y'] }}" x2="{{ $plot['width'] - 25 }}" y2="{{ $tick['y'] }}" stroke="var(--border)" />
                    <text class="sales-chart-axis" x="90" y="{{ $tick['y'] + 4 }}" text-anchor="end" fill="var(--muted)">₱{{ number_format($tick['amount'] / 100, 2) }}</text>
                @endforeach
                @if(count($chart) > 1)<polygon points="{{ $plot['area'] }}" fill="#941b0c0d" />@endif
                <polyline class="sales-chart-line" points="{{ $plot['line'] }}" fill="none" stroke="var(--oxblood)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                @foreach($chart as $i => $point)
                    <g><title>{{ $point['range'] }}{{ $point['current'] ? ' · in progress' : '' }}: ₱{{ number_format($point['amount'] / 100, 2) }} · {{ $point['orders'] }} paid orders</title><circle class="sales-chart-point" cx="{{ $plot['points'][$i]['x'] }}" cy="{{ $plot['points'][$i]['y'] }}" r="4" fill="var(--rust)" stroke="white" stroke-width="2" /></g>
                    @if(in_array($i, $plot['label_indices'], true))<text class="sales-chart-label" x="{{ $plot['points'][$i]['x'] }}" y="240" text-anchor="middle" fill="var(--muted)">{{ $point['label'] }}{{ $point['current'] ? '*' : '' }}</text>@endif
                @endforeach
            </svg>
        </div>
        <p class="muted chart-scroll-note">Swipe ↔ to see every date. Exact amounts are available below.</p>
        @if(!$salesChart['orders'])<p class="empty-note">No verified paid orders in this period yet.</p>@endif
        <details class="dashboard-help"><summary>About these sales dates and totals</summary>
        <p class="muted">{{ $salesChart['custom_range'] ? 'Selected dates: '.$salesChart['history'].' (inclusive).' : 'Showing '.$salesChart['history'].'. Cards and best sellers use all-time paid sales until dates are selected.' }} Paid clothing sales after discounts, before refunds, excluding delivery · Asia/Manila. {{ $period === 'weekly' ? 'Weeks start Monday. ' : '' }}@if(collect($chart)->contains('current', true))The current {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$period] }} is still in progress.@endif</p>
        <p class="muted sales-range-hint">Enter both dates or leave both blank. Daily: up to 120 days; weekly: up to 366 days; monthly/yearly: up to 10 years and 120 periods.</p>
        </details>
        <details class="forecast-details"><summary>View exact sales amounts</summary><div class="table-scroll"><table><thead><tr><th>Period</th><th>Paid orders</th><th>Confirmed clothing sale</th></tr></thead><tbody>
            @foreach($chart as $point)<tr><td>{{ $point['range'] }}{{ $point['current'] ? ' (in progress)' : '' }}</td><td>{{ $point['orders'] }}</td><td>₱{{ number_format($point['amount'] / 100, 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th>Total shown</th><th>{{ $salesChart['orders'] }}</th><th>₱{{ number_format($salesChart['total'] / 100, 2) }}</th></tr></tfoot></table></div></details>
    </section>
