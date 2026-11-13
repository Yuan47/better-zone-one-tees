<section class="panel dashboard-payment-panel" aria-labelledby="order-channel-heading">
    <div class="section-heading"><div><h2 id="order-channel-heading">Walk-in &amp; online orders</h2><p class="muted">Last 4 months · {{ $orderCharts['range'] }}</p></div></div>
    <svg class="dashboard-payment-donut" viewBox="0 0 180 180" role="img" aria-labelledby="channel-chart-title channel-chart-desc">
        <title id="channel-chart-title">Confirmed orders by sales channel</title>
        <desc id="channel-chart-desc">{{ $orderCharts['channel_total'] }} fully paid orders. @foreach($orderCharts['channels'] as $row){{ $row['label'] }}: {{ $row['count'] }}. @endforeach</desc>
        <circle cx="90" cy="90" r="65" fill="none" stroke="#f1e8e1" stroke-width="23"/>
        @php($offset = 0)
        @foreach($orderCharts['channels'] as $row)
            @if($row['count'])<circle cx="90" cy="90" r="65" fill="none" stroke="{{ $row['color'] }}" stroke-width="23" pathLength="100" stroke-dasharray="{{ $row['percentage'] }} 100" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 90 90)"/>@endif
            @php($offset += $row['percentage'])
        @endforeach
        <text x="90" y="91" text-anchor="middle" class="dashboard-donut-total">{{ number_format($orderCharts['channel_total']) }}</text>
        <text x="90" y="111" text-anchor="middle" class="dashboard-donut-caption">paid orders</text>
    </svg>
    <ul class="dashboard-channel-legend">
        @foreach($orderCharts['channels'] as $row)<li><i style="background:{{ $row['color'] }}" aria-hidden="true"></i><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong><small>{{ number_format($row['percentage'],2) }}%</small></li>@endforeach
    </ul>
    @if(!$orderCharts['channel_total'])<p class="empty-note">No confirmed walk-in or online orders in the last 4 months.</p>@endif
    <p class="muted">Counts fully paid invoices by payment date. The current month is still in progress.</p>
</section>
