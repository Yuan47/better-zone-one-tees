@if(auth()->user()?->role==='admin')
<div class="faq-list reminder-history">
@forelse($reminderHistory as $reminder)
<article class="panel reminder-card">
<div class="section-heading"><h3>
@if($showReminderOrder ?? false)
<a class="text-link" href="{{ route('orders.show',$reminder->order_id) }}">{{ $reminder->order?->number ?? 'Invoice #'.$reminder->order_id }} →</a>
@else
{{ $reminder->days_before }}-day reminder
@endif
</h3><span class="badge">{{ $reminder->status==='claimed'?'Awaiting send outcome':$reminder->label() }}</span></div>
<div class="two-col"><div><p><strong>Deadline:</strong> {{ $reminder->due_at->format('M d, Y') }} · {{ $reminder->days_before }} {{ $reminder->days_before===1?'day':'days' }} before</p><p><strong>Recorded balance:</strong> ₱{{ number_format($reminder->amount/100,2) }}</p><p style="overflow-wrap:anywhere"><strong>Recipient:</strong> {{ $reminder->recipient }}</p></div><div><p><strong>Recorded:</strong> {{ $reminder->created_at->format('M d, Y · g:i A') }}</p>@if($reminder->sent_at)<p><strong>Brevo accepted:</strong> {{ $reminder->sent_at->format('M d, Y · g:i A') }}</p>@elseif($reminder->status==='unknown')<p>The email service outcome could not be confirmed. Check Brevo before taking further action.</p>@elseif($reminder->status==='claimed')<p>This attempt was reserved for sending. If it stays here after the reminder run, check its outcome before taking further action.</p>@elseif($reminder->status==='failed')<p>The send failed. Review the email setup and Brevo activity before taking further action.</p>@elseif($reminder->status==='skipped')<p>The invoice, recipient or sending setup changed before the send. No email was sent for this attempt.</p>@endif</div></div>
@if($reminder->provider_message_id)<details><summary>Email service reference</summary><p style="overflow-wrap:anywhere">{{ $reminder->provider_message_id }}</p></details>@endif
</article>
@empty<div class="panel"><p class="muted">No reminder attempts have been recorded yet.</p></div>@endforelse
</div>
@endif
