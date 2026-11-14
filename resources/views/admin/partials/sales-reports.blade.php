<section class="dashboard-report-overview" id="reports" aria-label="Sales reports">
    <div class="panel dashboard-filter-panel">
        <div class="section-heading"><div><h2>Reports</h2><p class="muted">Choose the dates and how to group confirmed sales.</p></div><a class="button small" href="{{ route('admin.reports.export', ['period' => $period, 'start_date' => $salesChart['start_date'] ?? $chart[0]['start'], 'end_date' => $salesChart['end_date'] ?? $salesChart['today']]) }}">↓ Export payments</a></div>
        <form action="{{ request()->routeIs('admin.forecast') ? route('admin.forecast') : route('admin.dashboard') }}#reports" method="get" class="filters sales-range-form dashboard-date-filters report-filter-grid" aria-label="Sales chart period and date range" data-report-filters data-report-today="{{ $salesChart['today'] }}">
            <label class="report-preset-field" data-report-presets hidden>Quick dates<select data-report-preset>
                <option value="custom">Choose dates</option><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="last7">Last 7 days</option><option value="last30">Last 30 days</option><option value="this_week">This week</option><option value="this_month">This month</option><option value="this_year">This year</option>
            </select></label>
            <label for="sales-period">Group sales by<select name="period" id="sales-period">
                @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label for="sales-start-date">From<input id="sales-start-date" type="date" name="start_date" value="{{ $salesChart['start_date'] }}" max="{{ $salesChart['today'] }}"></label>
            <label for="sales-end-date">To<input id="sales-end-date" type="date" name="end_date" value="{{ $salesChart['end_date'] }}" max="{{ $salesChart['today'] }}"></label>
            <div class="report-filter-actions"><button class="button small" type="submit">View sales</button>
            <a class="text-link" href="{{ request()->routeIs('admin.forecast') ? route('admin.forecast', ['period' => $period]) : route('admin.dashboard', ['period' => $period]) }}#reports">Clear dates</a></div>
        </form>
        <div class="report-live-toolbar">
            <div><span class="report-refresh-state" data-report-status role="status" aria-live="polite">Automatic updates start when this page finishes loading.</span><small class="muted report-updated">Last checked <time data-report-updated datetime="{{ $reportUpdatedAt->toIso8601String() }}">{{ $reportUpdatedAt->format('M j, Y, g:i:s A') }}</time> · Asia/Manila</small></div>
            <button class="button small" type="button" data-report-refresh hidden>↻ Refresh data</button>
        </div>
        <p class="report-total-note">New payments appear after the system verifies them. Updates check every 15 seconds while this page is visible and active.</p>
        <noscript><p class="muted">Use View sales to refresh these figures. Automatic updates need JavaScript.</p></noscript>
    </div>
    <div class="{{ $showChannels ? 'dashboard-overview-charts' : 'report-single-chart' }}">
        @include('admin.partials.sales-chart')
        @if($showChannels)@include('admin.partials.order-channel-chart')@endif
    </div>
</section>
