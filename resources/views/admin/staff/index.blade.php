@extends('layout')
@section('title','Staff accounts')
@section('crumb','Staff accounts')
@section('content')
<div class="section-heading"><div><span class="eyebrow">STORE TEAM</span><h1>Staff accounts</h1><p class="muted">Set up your team’s access and verify each staff member’s email before activating their account.</p></div><a class="button dark" href="{{ route('admin.staff.create') }}">Add staff account →</a></div>
@if(!$setupReady)<div class="notice" role="status">Staff email setup is waiting for the store’s email configuration. You can save a pending setup now; it will not create an active account.</div>@endif
<div class="two-col"><article class="panel stat"><span>Active staff accounts</span><h2>{{ number_format($activeCount) }}</h2><small>Can sign in using their own account</small></article><article class="panel stat"><span>Pending email verification</span><h2>{{ number_format($pendingCount) }}</h2><small>No sign-in access until verified</small></article></div>
<section class="panel" aria-labelledby="pending-staff-heading"><div class="section-heading"><div><h2 id="pending-staff-heading">Pending staff setup</h2><p class="muted">Open a setup to send or enter the code received by the staff member.</p></div></div><div class="table-scroll"><table><caption class="sr-only">Staff accounts waiting for activation</caption><thead><tr><th>Staff member</th><th>Email</th><th>Created</th><th>Next step</th></tr></thead><tbody>
@forelse($invitations as $invitation)<tr><td>{{ $invitation->name }}</td><td>{{ $invitation->email }}</td><td>{{ $invitation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</td><td><a class="text-link" href="{{ route('admin.staff.show',$invitation) }}">Continue setup →</a></td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h3>No pending staff accounts.</h3><p>New staff setups will appear here until you verify their email.</p></div></td></tr>@endforelse
</tbody></table></div>
@if($invitations->hasPages())<nav class="pagination" aria-label="Pending staff pages">@if($invitations->previousPageUrl())<a href="{{ $invitations->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $invitations->currentPage() }} of {{ $invitations->lastPage() }}</span>@if($invitations->nextPageUrl())<a href="{{ $invitations->nextPageUrl() }}">Next →</a>@endif</nav>@endif
</section>
<section class="panel" aria-labelledby="active-staff-heading"><h2 id="active-staff-heading">Active staff</h2><div class="table-scroll"><table><caption class="sr-only">Active staff accounts</caption><thead><tr><th>Staff member</th><th>Email</th><th>Email verification</th><th>Account created</th></tr></thead><tbody>
@forelse($staff as $member)<tr><td>{{ $member->name }}</td><td>{{ $member->email }}</td><td>{{ $member->email_verified_at ? 'Verified' : 'Not yet verified' }}</td><td>{{ $member->created_at->timezone('Asia/Manila')->format('M j, Y') }}</td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h3>Your team starts here.</h3><p>Add a staff account and verify their email to give them access.</p></div></td></tr>@endforelse
</tbody></table></div>
@if($staff->hasPages())<nav class="pagination" aria-label="Staff pages">@if($staff->previousPageUrl())<a href="{{ $staff->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $staff->currentPage() }} of {{ $staff->lastPage() }}</span>@if($staff->nextPageUrl())<a href="{{ $staff->nextPageUrl() }}">Next →</a>@endif</nav>@endif
</section>
@endsection
