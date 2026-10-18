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
    <p class="muted">Sales basis: confirmed clothing sales before refunds. Demand basis: originally ordered, fully paid pieces before returns. New return inspections record returned quantities and clothing/delivery allocations; historical refunds may lack those details. These forecasts deliberately use original paid sales and pieces, with refunds reported separately. They do not represent sales after refunds or quantities kept by customers.</p>
    <details class="forecast-details"><summary>View historical periods and calculation inputs</summary><div class="table-scroll"><table><thead><tr><th>x</th><th>Period</th><th>Confirmed clothing sale (y)</th></tr></thead><tbody>
        @forelse($revenueForecast['months'] as $i => $month)<tr><td>{{ $i + 1 }}</td><td>{{ $month }}</td><td>₱{{ number_format($revenueForecast['values'][$i] / 100, 2) }}</td></tr>
        @empty<tr><td colspan="3">No completed months of paid orders yet.</td></tr>@endforelse
    </tbody></table></div></details>
    <h3>How well did past estimates perform?</h3>
    <p class="muted">We predict an earlier completed month using only the months before it, then compare the estimate with its actual sales. At least 2 test months are required for an error measure. A small sample is preliminary evidence, not a guarantee of future accuracy.</p>
    <div class="two-col forecast-methods">
        @foreach(['growth' => 'Historical growth', 'regression' => 'Linear regression'] as $method => $label)
        @php($check = $revenueForecast['backtest'][$method])
        <div class="method-card"><span class="eyebrow">{{ $label }} · historical error</span>
            @if($check['status'] === 'available')
            <h3>Average miss: ₱{{ number_format($check['mae'] / 100, 2) }}</h3>
            <p>{{ $check['tested_months'] }} test months · Total error relative to actual sales (WAPE): {{ $check['wape'] === null ? 'Unavailable — actual sales were zero' : number_format($check['wape'], 2).'% error' }}</p>
            @else
            <h3>Accuracy not measured yet</h3><p>{{ $check['tested_months'] }} usable test {{ $check['tested_months'] === 1 ? 'month' : 'months' }}. Needs {{ $check['minimum_training_months'] }} earlier months for each estimate and at least 2 usable test months. Growth also needs a positive earlier sales baseline.</p>
            @endif
        </div>
        @endforeach
    </div>
    <details class="forecast-details"><summary>View forecast error formulas and past estimates</summary>
        <p><b>Average miss (MAE)</b> = sum of absolute differences between forecast and actual ÷ number of test months. <b>WAPE</b> = sum of absolute differences ÷ sum of actual sales × 100. Lower error is better; this is not a guaranteed accuracy percentage. When actual sales total zero, WAPE cannot be calculated.</p>
        @foreach(['growth' => 'Historical growth', 'regression' => 'Linear regression'] as $method => $label)
        <h4>{{ $label }}</h4><div class="table-scroll"><table><thead><tr><th>Test month</th><th>Earlier months used</th><th>Forecast</th><th>Actual</th><th>Absolute difference</th></tr></thead><tbody>
            @forelse($revenueForecast['backtest'][$method]['points'] as $point)<tr><td>{{ $point['month'] }}</td><td>{{ $point['training_months'] }}</td><td>₱{{ number_format($point['prediction'] / 100, 2) }}</td><td>₱{{ number_format($point['actual'] / 100, 2) }}</td><td>₱{{ number_format($point['absolute_error'] / 100, 2) }}</td></tr>
            @empty<tr><td colspan="5">No usable test months yet.</td></tr>@endforelse
        </tbody></table></div>
        @endforeach
    </details>
    <h3>Product demand · pieces per month</h3>
    <p class="muted">Historical units combine all sizes and colors for each product across its own completed months, up to 6 months. Product history starts in the month it was added, or an earlier verified paid-sale month for imported history. Months before that are excluded. Products without any completed-month paid sales need sales history before demand can be estimated.</p>
    <div class="table-scroll"><table><thead><tr><th>Product</th><th>Historical units</th><th>Growth forecast</th><th>Regression forecast</th><th>Available</th><th>Suggested reorder</th></tr></thead><tbody>
        @forelse($forecasts as $row)<tr><td>{{ $row['name'] }}<small>{{ count($row['months']) }} completed {{ count($row['months']) === 1 ? 'month' : 'months' }}</small>@if(count($row['months']))<small>{{ $row['months'][0] }} to {{ $row['months'][count($row['months']) - 1] }}</small>@endif</td><td>{{ $row['sold'] }}</td><td>{{ $row['growth']['prediction'] === null ? 'Needs history' : ceil($row['growth']['prediction']).' pcs' }}<small>{{ $row['growth']['rate'] === null ? '' : number_format($row['growth']['rate'] * 100, 1).'% growth' }}</small></td><td>{{ $row['forecast'] === null ? 'Needs history' : $row['forecast'].' pcs' }}</td><td>{{ $row['available'] }}</td><td>{{ $row['reorder'] ?? '—' }}</td></tr>
        @empty<tr><td colspan="6">Add active products to see demand estimates.</td></tr>@endforelse
    </tbody></table></div>
    @foreach($forecasts as $row)
    <details class="forecast-details"><summary>{{ $row['name'] }} · history and calculation inputs</summary><p>{{ $row['history_basis'] }} establishes the first eligible month. {{ $row['observed_history'] ? 'Only eligible completed months are used; zero-sale months within that history are included.' : 'No verified paid pieces in a completed month yet; zero demand is not assumed.' }}</p><p>a: {{ $row['regression']['a'] === null ? '—' : number_format($row['regression']['a'], 2).' pieces' }} · b: {{ $row['regression']['b'] === null ? '—' : number_format($row['regression']['b'], 2).' pieces/month' }} · target x: {{ count($row['months']) + 1 }}</p><div class="table-scroll"><table><thead><tr><th>x</th><th>Period</th><th>Paid pieces (y)</th></tr></thead><tbody>@forelse($row['months'] as $i => $month)<tr><td>{{ $i + 1 }}</td><td>{{ $month }}</td><td>{{ $row['values'][$i] }}</td></tr>@empty<tr><td colspan="3">No eligible completed months yet.</td></tr>@endforelse</tbody></table></div>
        <h4>Historical forecast error</h4><p>Test estimates are rounded up to whole pieces, just like the demand estimates above. Current stock does not affect these error measures.</p>
        <div class="table-scroll"><table><thead><tr><th>Method</th><th>Test months</th><th>Average miss (MAE)</th><th>Relative error (WAPE)</th></tr></thead><tbody>
            @foreach(['growth' => 'Historical growth', 'regression' => 'Linear regression'] as $method => $label)
            @php($check = $row['backtest'][$method])
            <tr><td>{{ $label }}</td><td>{{ $check['tested_months'] }}</td><td>{{ $check['mae'] === null ? 'Needs at least 2 usable test months' : number_format($check['mae'], 2).' pieces' }}</td><td>{{ $check['wape'] === null ? 'Unavailable' : number_format($check['wape'], 2).'% error' }}</td></tr>
            @endforeach
        </tbody></table></div>
    </details>
    @endforeach
    <details class="forecast-details"><summary>What do intercept, slope, and suggested reorder mean?</summary><p><b>Intercept (a)</b> anchors the fitted line at month 0; it is not a recorded sale. <b>Slope (b)</b> is the estimated change in sales for each additional month, expressed in pesos for the sales forecast.</p><p><b>Suggested reorder</b> = max(0, rounded-up regression demand − available stock). Available stock accounts for quantities held by pending orders. This compares the full target month with stock currently available; it does not account for this month’s sales, supplier lead times, incoming purchases, safety stock, or individual size and color needs.</p></details>
    <p class="muted">Negative regression estimates display as zero demand. Delivery charges are excluded from forecast sales.</p>
</section>
@endsection
