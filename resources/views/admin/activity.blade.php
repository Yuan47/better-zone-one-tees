@extends('layout')
@section('title','Activity Log')
@section('crumb','Activity Log')
@section('content')
<div class="page-heading"><span class="eyebrow">STORE OVERSIGHT</span><h1>Activity Log</h1><p class="muted">A record of completed account, shopping, and store actions. {{ number_format($logs->total()) }} matching entries.</p></div>
<form action="{{ route('admin.activity.index') }}" class="panel stack" method="get">
<div class="three-col"><label>Search actor or action<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Name, action, or record number"></label>
<label>Actor role<select name="role"><option value="">All roles</option>@foreach(['customer','staff','admin'] as $role)<option value="{{ $role }}" @selected(($filters['role']??'')===$role)>{{ ucfirst($role) }}</option>@endforeach</select></label>
<label>Event<select name="event"><option value="">All events</option>@foreach($events as $event=>$label)<option value="{{ $event }}" @selected(($filters['event']??'')===$event)>{{ $label }}</option>@endforeach</select></label></div>
<div class="three-col"><label>From date<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>To date<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label><div class="stack"><button class="button dark">Apply filters</button><a class="text-link" href="{{ route('admin.activity.index') }}">Clear filters</a></div></div></form>
<section class="panel"><div class="table-scroll"><table><thead><tr><th>When · Philippine time</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td>{{ $log->occurred_at->timezone('Asia/Manila')->format('M j, Y') }}<small>{{ $log->occurred_at->format('g:i:s A') }}</small></td><td>{{ $log->actor_name }}<small>{{ ucfirst($log->actor_role) }}@if($log->actor_id) · Account #{{ $log->actor_id }}@endif</small></td><td>{{ $log->label() }}</td><td>{{ $log->targetLabel() }}</td><td>@forelse($log->details() as $detail)<div>{{ $detail }}</div>@empty<span class="muted">—</span>@endforelse</td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><h2>No activity found.</h2><p>Completed actions will appear here. Try changing your filters.</p></div></td></tr>@endforelse
</tbody></table></div>
@if($logs->hasPages())<nav class="pagination" aria-label="Activity pages">@if($logs->previousPageUrl())<a href="{{ $logs->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>@if($logs->nextPageUrl())<a href="{{ $logs->nextPageUrl() }}">Next →</a>@endif</nav>@endif</section>
<div class="mini-note">Only signed-in users’ completed actions are recorded. Browsing, chat messages, failed attempts, and unchanged saves are excluded. Entries contain field names and summaries; passwords, addresses, payment credentials, and session details are excluded.</div>
@endsection
