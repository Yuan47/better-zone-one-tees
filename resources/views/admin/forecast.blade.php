@extends('layout')
@section('title', 'Sales & Growth Forecast')
@section('crumb', 'Sales & Growth Forecast')
@section('content')
<div class="section-heading"><div><span class="eyebrow">PLAN AHEAD</span><h1>Sales &amp; Growth Forecast</h1><p class="muted">Demand estimates for {{ $revenueForecast['target'] }}.</p></div><a class="button" href="{{ route('admin.dashboard') }}#reports">← Sales reports</a></div>
<section class="panel forecast-panel">
    <p class="muted">Uses up to 6 completed calendar months of verified paid orders. The current month is forecast from completed months, so partial sales do not distort the estimate. Historical growth needs 2 months with a nonzero earlier month; regression needs 3 completed months. Estimates are not guarantees.</p>
    <div class="two-col forecast-methods">
        <div class="method-card"><span class="eyebrow">HISTORICAL GROWTH</span><h3>Previous period sales × (1 + growth rate)</h3><p>Growth rate = (last completed month − month before) / month before.</p><h2>{{ $revenueForecast['growth']['prediction'] === null ? 'Needs sales history' : '₱'.number_format($revenueForecast['growth']['prediction'] / 100, 2) }}</h2><small>Confirmed clothing sale forecast · Rate {{ $revenueForecast['growth']['rate'] === null ? 'unavailable' : number_format($revenueForecast['growth']['rate'] * 100, 2).'%' }}</small></div>
        <div class="method-card"><span class="eyebrow">LINEAR REGRESSION</span><h3>y = a + bx</h3><p>y = confirmed clothing sale; x = chronological month number; a = intercept; b = change per month.</p><h2>{{ $revenueForecast['regression']['prediction'] === null ? 'Needs sales history' : '₱'.number_format($revenueForecast['regression']['prediction'] / 100, 2) }}</h2><small>a: {{ $revenueForecast['regression']['a'] === null ? '—' : '₱'.number_format($revenueForecast['regression']['a'] / 100, 2) }} · b: {{ $revenueForecast['regression']['b'] === null ? '—' : '₱'.number_format($revenueForecast['regression']['b'] / 100, 2) }} · target x: {{ count($revenueForecast['months']) + 1 }}</small></div>
    </div>
    <details class="forecast-details"><summary>View historical periods and calculation inputs</summary><div class="table-scroll"><table><thead><tr><th>x</th><th>Period</th><th>Confirmed clothing sale (y)</th></tr></thead><tbody>
        @forelse($revenueForecast['months'] as $i => $month)<tr><td>{{ $i + 1 }}</td><td>{{ $month }}</td><td>₱{{ number_format($revenueForecast['values'][$i] / 100, 2) }}</td></tr>
        @empty<tr><td colspan="3">No completed months of paid orders yet.</td></tr>@endforelse
    </tbody></table></div></details>
    <h3>Product demand · pieces per month</h3>
    <p class="muted">Historical units add up paid pieces across the completed months shown above, combining all sizes and colors for each product.</p>
    <div class="table-scroll"><table><thead><tr><th>Product</th><th>Historical units</th><th>Growth forecast</th><th>Regression forecast</th><th>Available</th><th>Suggested reorder</th></tr></thead><tbody>
        @forelse($forecasts as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['sold'] }}</td><td>{{ $row['growth']['prediction'] === null ? 'Needs history' : ceil($row['growth']['prediction']).' pcs' }}<small>{{ $row['growth']['rate'] === null ? '' : number_format($row['growth']['rate'] * 100, 1).'% growth' }}</small></td><td>{{ $row['forecast'] === null ? 'Needs history' : $row['forecast'].' pcs' }}</td><td>{{ $row['available'] }}</td><td>{{ $row['reorder'] ?? '—' }}</td></tr>
        @empty<tr><td colspan="6">Add active products to see demand estimates.</td></tr>@endforelse
    </tbody></table></div>
    <details class="forecast-details"><summary>What do intercept, slope, and suggested reorder mean?</summary><p><b>Intercept (a)</b> anchors the fitted line at month 0; it is not a recorded sale. <b>Slope (b)</b> is the estimated change in sales for each additional month, expressed in pesos for the sales forecast.</p><p><b>Suggested reorder</b> = max(0, rounded-up regression demand − available stock). Available stock accounts for quantities held by pending orders. This compares the full target month with stock currently available; it does not account for this month’s sales, supplier lead times, incoming purchases, safety stock, or individual size and color needs.</p></details>
    <p class="muted">Negative regression estimates display as zero demand. Delivery charges are excluded from forecast sales.</p>
</section>
@endsection
