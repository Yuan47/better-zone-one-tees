@if(in_array(auth()->user()?->role,['staff','admin'],true))
@php($readyEmail = \App\Models\OrderReadyNotification::where('order_id',$order->id)->first())
@if($readyEmail)
<section class="panel" aria-labelledby="ready-email-title">
<h2 id="ready-email-title">Customer readiness email</h2>
<p><strong>{{ $readyEmail->label() }}</strong></p>
@if($readyEmail->sent_at)<p>Accepted on {{ $readyEmail->sent_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}. Provider acceptance does not confirm inbox delivery.</p>
@elseif(in_array($readyEmail->status,['claimed','unknown'],true))<p>Check this attempt in Brevo before contacting the customer. Automatic and manual resending are blocked because delivery may have occurred.</p>
@elseif($readyEmail->status==='skipped')<p>No email was sent because this order or its customer email changed. Review the order before contacting the customer.</p>
@endif
@if(in_array($readyEmail->status,['queued','failed'],true) && $order->status==='ready')
@if(app(\App\Services\ReadyOrderNotifications::class)->ready())
<p class="muted">This reminder uses the customer's saved email. Only an unsent or confirmed rejected attempt can be retried.</p>
<form class="stack" action="{{ route('admin.orders.ready-email.retry',$order) }}" method="post">@csrf<label>Reason for sending again<textarea name="reason" required minlength="10" maxlength="1000" rows="2" placeholder="Explain the email setup check or why the unsent message should be sent."></textarea></label><button class="button">Send readiness email</button></form>
@else<p>Readiness email sending is disabled or its connection is incomplete. The order remains saved.</p>
@endif
@endif
</section>
@endif
@endif
