<section class="delivery-overview" aria-labelledby="delivery-status-heading" id="delivery-status">
    <div class="section-heading delivery-section-heading">
        <div><span class="eyebrow">COURIER DELIVERIES</span><h2 id="delivery-status-heading">Where are our deliveries?</h2><p>Current status of paid Lalamove orders placed in your selected dates. Store pickups are counted separately.</p></div>
        <a class="button" href="{{ route('admin.orders', $filters + ['payment_status'=>'paid', 'delivery_method'=>'lalamove']) }}">View delivery orders →</a>
    </div>
    <div class="delivery-summary" aria-label="Delivery summary">
        <div><span>Delivery orders</span><strong>{{ number_format($deliveryTotals['total']) }}</strong><small>Paid orders in this date range</small></div>
        <div><span>Delivered</span><strong>{{ number_format($deliveryTotals['completed']) }}</strong><small>Delivery marked completed</small></div>
        <div><span>Still open</span><strong>{{ number_format($deliveryTotals['active']) }}</strong><small>Includes preparation and courier handover</small></div>
        <div class="delivery-attention"><span>Booking / delivery checks</span><strong>{{ number_format($deliveryTotals['attention']) }}</strong><small>Check the existing booking before retrying</small></div>
    </div>
    <div class="delivery-chart-grid">
        <section class="panel delivery-chart-panel" aria-labelledby="delivery-share-heading">
            <h3 id="delivery-share-heading">Delivery status at a glance</h3>
            <p class="muted">Each delivery appears once. The ring shows its share of all {{ number_format($deliveryTotals['total']) }} delivery orders.</p>
            @php($circumference = 2 * M_PI * 118)
            @php($offset = 0)
            <svg class="delivery-donut" width="360" height="360" viewBox="0 0 360 360" role="img" aria-labelledby="delivery-donut-title delivery-donut-description">
                <title id="delivery-donut-title">Current delivery status distribution</title>
                <desc id="delivery-donut-description">{{ $deliveryTotals['total'] }} paid Lalamove orders placed from {{ $startDate }} through {{ $endDate }}. @foreach($deliveryRows as $row){{ $row['label'] }}: {{ $row['count'] }}. @endforeach Exact counts and staff guidance follow.</desc>
                <circle cx="180" cy="180" r="118" fill="none" stroke="#f0e7e1" stroke-width="32"/>
                @foreach($deliveryRows as $row)
                    @if($row['count'])
                        @php($segment = $row['percentage'] / 100 * $circumference)
                        <circle cx="180" cy="180" r="118" fill="none" stroke="{{ $row['color'] }}" stroke-width="32" stroke-dasharray="{{ $segment }} {{ $circumference - $segment }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 180 180)"><title>{{ $row['label'] }}: {{ $row['count'] }} ({{ number_format($row['percentage'], 1) }}%)</title></circle>
                        @php($offset += $segment)
                    @endif
                @endforeach
                <text x="180" y="174" text-anchor="middle" class="delivery-donut-count">{{ number_format($deliveryTotals['total']) }}</text>
                <text x="180" y="205" text-anchor="middle" class="delivery-donut-label">delivery orders</text>
            </svg>
            @if(!$deliveryTotals['total'])<p class="delivery-empty">No paid delivery orders in this range. Choose another date range to see their statuses.</p>@endif
            <ul class="delivery-chart-legend" aria-label="Delivery chart legend">
                @foreach($deliveryRows as $row)
                    @if($row['status'] !== 'other' || $row['count'])<li><span class="delivery-color" style="background:{{ $row['color'] }}" aria-hidden="true"></span><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></li>@endif
                @endforeach
            </ul>
        </section>
        <section class="panel delivery-chart-panel" aria-labelledby="delivery-count-heading">
            <h3 id="delivery-count-heading">Deliveries by current status</h3>
            <p class="muted">See the count and the next step. Open a status to view only its paid delivery orders.</p>
            <div class="delivery-status-bars">
                @foreach($deliveryRows as $row)
                    @if($row['status'] !== 'other' || $row['count'])
                        <article class="delivery-status-item">
                            <div class="delivery-status-title"><a href="{{ $row['url'] }}">{{ $row['label'] }} <span aria-hidden="true">↗</span></a><strong>{{ number_format($row['count']) }} <span>{{ $row['count'] === 1 ? 'order' : 'orders' }}</span></strong></div>
                            <div class="delivery-bar-track" aria-hidden="true"><span style="width:{{ $row['percentage'] }}%;background:{{ $row['color'] }}"></span></div>
                            <div class="delivery-status-help"><p>{{ $row['description'] }}</p><span>{{ number_format($row['percentage'], 1) }}%</span></div>
                        </article>
                    @endif
                @endforeach
            </div>
        </section>
    </div>
    <p class="delivery-snapshot-note">These charts show the current status of orders placed in the selected dates, as of {{ $now->format('M j, Y · g:i A') }}. They do not show when a delivery changed status. Canceled bookings return to “Ready for courier” only after provider verification.</p>
</section>
