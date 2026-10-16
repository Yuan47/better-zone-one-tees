@if(auth()->user()?->role==='admin')
@php($reminderHistory = \App\Models\PaymentReminder::where('order_id',$order->id)->latest('id')->limit(12)->get())
<section class="settings-section invoice-reminders" aria-labelledby="invoice-reminders-title">
<div class="section-heading"><div><span class="eyebrow">OWNER ONLY</span><h2 id="invoice-reminders-title">Email reminder history</h2><p class="muted">Latest reminder attempts for this invoice. Reminders use its recorded deadline and remaining balance.</p></div><a class="text-link" href="{{ route('admin.settings') }}#payment-reminders">Reminder settings →</a></div>
<p class="muted">Reminders run 3 days and 1 day before the deadline in Manila time. Accepted by Brevo does not confirm inbox delivery. Unknown or interrupted attempts are not automatically resent.</p>
@include('admin.partials.reminder-history', ['showReminderOrder'=>false])
</section>
@endif
