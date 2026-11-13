@auth
@if((auth()->user()->role ?? auth()->user()->fresh()?->role) === 'customer')
@php
    $openPaymentAlerts = $paymentAlerts ?? (session('payment_alert') ? app(\App\Services\InstallmentCredit::class)->alertsFor(auth()->user()) : collect());
    $openPaymentAlerts = $openPaymentAlerts->where('user_id', auth()->id());
@endphp
@if($openPaymentAlerts->isNotEmpty())
<section id="payment-alert" class="notice stack" aria-label="Outstanding payments">
    <strong>{{ $openPaymentAlerts->count() === 1 ? 'You have an outstanding payment.' : 'You have outstanding payments.' }}</strong>
    @if($openPaymentAlerts->contains('payment_plan', 'partial'))
    <p>This older invoice keeps its recorded payments. Settle its full remaining balance on the same invoice. New orders require full payment.</p>
    @endif
    <ul>
        @foreach($openPaymentAlerts as $paymentAlert)
        <li>
            <strong>{{ $paymentAlert->number }}</strong> · Remaining ₱{{ number_format($paymentAlert->outstandingBalance() / 100, 2) }}
            @if($paymentAlert->due_at)
            · Due {{ $paymentAlert->due_at->format('M d, Y · g:i A') }}{{ $paymentAlert->invoiceOverdue() ? ' · Overdue' : '' }}
            @else
            · Payment pending
            @endif
            · <a class="text-link" href="{{ route('orders.show', $paymentAlert) }}">Open invoice →</a>
        </li>
        @endforeach
    </ul>
</section>
@endif
@endif
@endauth
