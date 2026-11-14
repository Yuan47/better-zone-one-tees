<section data-report-region="top-items" class="panel top-items-panel" aria-labelledby="top-items-heading">
    <div class="section-heading"><div><h2 id="top-items-heading">Top 5 most bought items</h2><p class="muted">Last 4 months · {{ $orderCharts['range'] }} · pieces per month</p></div></div>
    @if(count($orderCharts['series']))
    <div class="top-items-plot"><svg viewBox="0 0 780 280" role="img" aria-labelledby="top-items-title top-items-desc">
        <title id="top-items-title">Monthly quantities for the five most bought products</title>
        <desc id="top-items-desc">Each color follows one product. Higher points mean more pieces sold. Exact monthly quantities are in the table below. The current month is incomplete.</desc>
        @foreach($orderCharts['ticks'] as $tick)<line x1="80" y1="{{ $tick['y'] }}" x2="745" y2="{{ $tick['y'] }}" stroke="#eaded5"/><text x="68" y="{{ $tick['y']+4 }}" text-anchor="end">{{ number_format($tick['value'],2) }}</text>@endforeach
        @foreach($orderCharts['months'] as $i=>$month)<text x="{{ 90+210*$i }}" y="259" text-anchor="middle">{{ $month['label'] }}{{ $month['current']?'*':'' }}</text>@endforeach
        @foreach($orderCharts['series'] as $line)
            <polyline points="{{ $line['polyline'] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="3" stroke-linejoin="round"/>
            @foreach($line['points'] as $i=>$point)<circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4.5" fill="{{ $line['color'] }}" stroke="#fff" stroke-width="2"><title>{{ $line['name'] }} · {{ $orderCharts['months'][$i]['label'] }}: {{ number_format($point['value'],2) }} pieces</title></circle>@endforeach
        @endforeach
    </svg></div>
    <div class="top-items-legend">@foreach($orderCharts['series'] as $line)<span><i style="background:{{ $line['color'] }}" aria-hidden="true"></i>{{ $line['name'] }}</span>@endforeach</div>
    <details class="dashboard-help"><summary>View exact monthly quantities</summary><div class="table-scroll"><table data-sortable><thead><tr><th data-sort-type="text">Product</th>@foreach($orderCharts['months'] as $month)<th data-sort-type="number">{{ $month['label'] }}</th>@endforeach<th data-sort-type="number">Total pieces</th></tr></thead><tbody>@foreach($orderCharts['series'] as $line)<tr data-sort-row><td data-sort-value="{{ $line['name'] }}">{{ $line['name'] }}</td>@foreach($line['values'] as $value)<td data-sort-value="{{ $value }}">{{ number_format($value,2) }}</td>@endforeach<td data-sort-value="{{ $line['total'] }}">{{ number_format($line['total'],2) }}</td></tr>@endforeach</tbody></table></div></details>
    @else<p class="empty-note">The top items will appear when fully paid orders are recorded in these four months.</p>@endif
    <p class="muted">Updates from recorded fully paid purchases across all sizes and colors of each product. Original pieces sold before returns. * Current month to date.</p>
</section>
