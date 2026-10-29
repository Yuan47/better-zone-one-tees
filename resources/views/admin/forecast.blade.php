@extends('layout')
@section('title', 'Sales & Growth Forecast')
@section('crumb', 'Sales & Growth Forecast')
@section('content')
<style>
    .forecast-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;margin:26px 0 30px}
    .forecast-chart-card{min-width:0;margin:0;padding:24px 20px;background:#fff;border:1px solid var(--border);border-radius:12px}
    .forecast-chart-card h2{margin:0 0 8px}.forecast-chart-card figcaption>p{font-size:13px;line-height:1.65;margin:0 0 12px;color:var(--muted)}
    .forecast-chart-total{display:block;font-size:24px;font-weight:700;color:var(--coffee);margin-bottom:5px}.forecast-chart-target{display:block;font-size:12px;color:var(--muted);margin-bottom:18px}
    .forecast-chart-legend{display:flex;flex-wrap:wrap;gap:10px 18px;padding:0;margin:0 0 16px;list-style:none;font-size:12px;color:#621708}
    .forecast-chart-legend li{display:flex;align-items:center;gap:7px}.forecast-chart-key{width:27px;border-top:3px solid #941b0c;display:inline-block}.forecast-chart-key.estimate{border-color:#bc3908;border-top-style:dashed}
    .forecast-chart-scroll{overflow-x:auto;overscroll-behavior-x:contain;border-radius:8px}.forecast-chart-scroll:focus-visible{outline:2px solid #941b0c;outline-offset:4px}
    .forecast-sales-svg{display:block;width:100%;min-width:460px;height:auto}.forecast-sales-svg text{font-family:inherit;font-size:13px;fill:#735b52}.forecast-sales-svg .forecast-axis-title{font-size:12px}.forecast-sales-svg .forecast-target-label{fill:#621708;font-weight:600}
    .forecast-chart-note{font-size:12px;line-height:1.65;margin:12px 0 0;color:var(--muted)}.forecast-chart-card .forecast-details{margin:18px 0 0;font-size:12px}.forecast-chart-card table caption{text-align:left;padding:0 0 10px;color:var(--muted)}
    .forecast-chart-empty{display:flex;min-height:260px;align-items:center;justify-content:center;padding:28px;text-align:center;background:#fff8f0;border:1px dashed #e3d1c2;border-radius:8px;color:#735b52;font-size:14px;line-height:1.7}
    @media(max-width:1180px){.forecast-chart-grid{grid-template-columns:minmax(0,1fr)}}
    @media(max-width:600px){.forecast-chart-grid{gap:20px;margin-top:22px}.forecast-chart-card{padding:20px 14px}.forecast-chart-total{font-size:25px}.forecast-chart-card .forecast-details{font-size:14px}.forecast-chart-legend,.forecast-chart-note{font-size:13px}}
</style>
<div class="section-heading"><div><span class="eyebrow">PLAN AHEAD</span><h1>Sales &amp; Growth Forecast</h1><p class="muted">Demand estimates for {{ $revenueForecast['target'] }}.</p></div><a class="button" href="{{ route('admin.dashboard') }}#reports">← Sales reports</a></div>
<section class="panel forecast-panel">
    <p class="muted">Uses up to 6 completed calendar months of verified paid orders. The current month is forecast from completed months, so partial sales do not distort the estimate. Historical growth needs 2 months with a nonzero earlier month; regression needs 3 completed months. Estimates are not guarantees.</p>
    @php
        $historyMonths = array_values($revenueForecast['months']);
        $historyValues = array_values($revenueForecast['values']);
        $historyCount = count($historyMonths);
        // Both charts share a zero-based scale and keep the service's centavo precision.
        $scaleValues = array_merge([0], $historyValues);
        foreach (['growth', 'regression'] as $method) {
            if ($revenueForecast[$method]['prediction'] !== null) {
                $scaleValues[] = $revenueForecast[$method]['prediction'];
            }
        }
        $rawStep = max(100, (max($scaleValues) - min($scaleValues)) / 4);
        $stepMagnitude = 10 ** floor(log10($rawStep));
        $tickStep = ceil($rawStep / $stepMagnitude) * $stepMagnitude;
        $axisMin = floor(min($scaleValues) / $tickStep) * $tickStep;
        $axisMax = max($axisMin + $tickStep, ceil(max($scaleValues) / $tickStep) * $tickStep);
        $plotLeft = 92; $plotRight = 526; $plotTop = 34; $plotBottom = 244;
        $chartX = static fn ($i) => round($plotLeft + ($plotRight - $plotLeft) * $i / max(1, $historyCount), 2);
        $chartY = static fn ($value) => round($plotBottom - ($value - $axisMin) / ($axisMax - $axisMin) * ($plotBottom - $plotTop), 2);
        $actualPoints = [];
        foreach ($historyValues as $i => $value) {
            $actualPoints[] = $chartX($i).','.$chartY($value);
        }
        $targetShort = \Carbon\Carbon::createFromFormat('!F Y', $revenueForecast['target'])->format('M Y');
        $targetBoundary = $historyCount ? ($chartX($historyCount - 1) + $chartX($historyCount)) / 2 : $plotRight;
    @endphp
    <div class="forecast-chart-grid" aria-label="Historical sales and next-month forecasts">
        @foreach(['growth' => 'Historical growth forecast', 'regression' => 'Sales forecast'] as $method => $label)
        @php
            $estimate = $revenueForecast[$method]['prediction'];
            $chartId = 'forecast-'.$method;
        @endphp
        <figure class="forecast-chart-card">
            <figcaption>
                <h2 id="{{ $chartId }}-heading">{{ $label }}</h2>
                <p>{{ $method === 'growth' ? 'See the latest monthly growth carried forward to the forecast month.' : 'See the overall sales trend across the completed months.' }}</p>
                <span class="forecast-chart-total">{{ $estimate === null ? 'Needs sales history' : '₱'.number_format($estimate / 100, 2) }}</span>
                <span class="forecast-chart-target">{{ $revenueForecast['target'] }} estimate · clothing sales before refunds</span>
            </figcaption>
            <ul class="forecast-chart-legend" aria-label="Chart legend">
                <li><span class="forecast-chart-key" aria-hidden="true"></span>Actual paid sales</li>
                @if($estimate !== null)<li><span class="forecast-chart-key estimate" aria-hidden="true"></span>Forecast</li>@endif
            </ul>
            @if($historyCount)
            <div class="forecast-chart-scroll" tabindex="0" role="region" aria-label="{{ $label }} chart; scroll horizontally on a small screen">
                <svg class="forecast-sales-svg" viewBox="0 0 560 300" role="img" aria-labelledby="{{ $chartId }}-title {{ $chartId }}-description">
                    <title id="{{ $chartId }}-title">{{ $label }}: actual monthly sales and {{ $revenueForecast['target'] }} estimate</title>
                    <desc id="{{ $chartId }}-description">Solid dark red shows actual confirmed clothing sales for {{ $historyCount }} completed months. @if($estimate !== null)Dashed orange shows the {{ $revenueForecast['target'] }} forecast of ₱{{ number_format($estimate / 100, 2) }}. @else There is not enough usable history for a forecast.@endif Delivery fees are excluded. Exact amounts are in the table below.</desc>
                    <rect x="{{ $targetBoundary }}" y="{{ $plotTop }}" width="{{ 548 - $targetBoundary }}" height="{{ $plotBottom - $plotTop }}" fill="#fff4d8" />
                    <text class="forecast-axis-title" x="{{ $plotLeft }}" y="17">Confirmed clothing sale (₱)</text>
                    @for($tick = $axisMin; $tick <= $axisMax; $tick += $tickStep)
                    <line x1="{{ $plotLeft }}" y1="{{ $chartY($tick) }}" x2="548" y2="{{ $chartY($tick) }}" stroke="#eadfd7" />
                    <text x="{{ $plotLeft - 10 }}" y="{{ $chartY($tick) + 4 }}" text-anchor="end">₱{{ number_format($tick / 100, 0) }}</text>
                    @endfor
                    <line x1="{{ $targetBoundary }}" y1="{{ $plotTop }}" x2="{{ $targetBoundary }}" y2="{{ $plotBottom }}" stroke="#d7b675" stroke-dasharray="4 5" />
                    @if($historyCount > 1)<polyline points="{{ implode(' ', $actualPoints) }}" fill="none" stroke="#941b0c" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />@endif
                    @foreach($historyMonths as $i => $month)
                    <g><title>{{ $month }} actual paid sales: ₱{{ number_format($historyValues[$i] / 100, 2) }}</title><circle cx="{{ $chartX($i) }}" cy="{{ $chartY($historyValues[$i]) }}" r="4.5" fill="#941b0c" stroke="#fff" stroke-width="2" /></g>
                    <text x="{{ $chartX($i) }}" y="267" text-anchor="middle">{{ \Carbon\Carbon::createFromFormat('!Y-m', $month)->format('M') }}</text>
                    <text x="{{ $chartX($i) }}" y="285" text-anchor="middle">{{ substr($month, 0, 4) }}</text>
                    @endforeach
                    @if($estimate !== null)
                    <line x1="{{ $chartX($historyCount - 1) }}" y1="{{ $chartY($historyValues[$historyCount - 1]) }}" x2="{{ $chartX($historyCount) }}" y2="{{ $chartY($estimate) }}" stroke="#bc3908" stroke-width="3" stroke-dasharray="7 5" />
                    <g><title>{{ $revenueForecast['target'] }} forecast: ₱{{ number_format($estimate / 100, 2) }}. This is an estimate, not a confirmed sale.</title><circle cx="{{ $chartX($historyCount) }}" cy="{{ $chartY($estimate) }}" r="6" fill="#fff" stroke="#bc3908" stroke-width="3" /></g>
                    @endif
                    <text class="forecast-target-label" x="{{ $chartX($historyCount) }}" y="267" text-anchor="middle">{{ $targetShort }}</text>
                    <text class="forecast-target-label" x="{{ $chartX($historyCount) }}" y="285" text-anchor="middle">Estimate</text>
                </svg>
            </div>
            @else
            <div class="forecast-chart-empty">No completed months of paid sales yet.<br>Actual sales will appear after a calendar month is complete.</div>
            @endif
            <p class="forecast-chart-note">
                @if($estimate === null)
                    @if($method === 'growth' && ($revenueForecast['growth']['status'] ?? '') === 'zero_baseline')Growth cannot be calculated because the earlier month has no sales. No forecast is plotted.
                    @else{{ $method === 'growth' ? 'Needs at least 2 completed months and a positive earlier sales baseline.' : 'Needs at least 3 completed months to fit a sales trend.' }} No forecast is plotted.@endif
                @else
                    The dashed line leads to the shaded forecast month. {{ $method === 'growth' ? 'It follows the change between the last two completed months.' : 'It uses the overall trend across all completed months (linear regression).' }}
                @endif
                Both charts use the same peso scale. On small screens, swipe the chart to see every month.
            </p>
            <details class="forecast-details"><summary>View exact chart amounts</summary><div class="table-scroll"><table>
                <caption>{{ $label }} · Philippine pesos, before refunds and excluding delivery</caption>
                <thead><tr><th scope="col">Period</th><th scope="col">Actual paid sales</th><th scope="col">Forecast</th></tr></thead><tbody>
                    @forelse($historyMonths as $i => $month)
                    <tr><th scope="row">{{ $month }}</th><td>₱{{ number_format($historyValues[$i] / 100, 2) }}</td><td>—</td></tr>
                    @empty<tr><td colspan="3">No completed months of paid orders yet.</td></tr>@endforelse
                    <tr><th scope="row">{{ $revenueForecast['target'] }} (forecast)</th><td>—</td><td>{{ $estimate === null ? 'Needs usable sales history' : '₱'.number_format($estimate / 100, 2) }}</td></tr>
                </tbody></table></div></details>
        </figure>
        @endforeach
    </div>
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
