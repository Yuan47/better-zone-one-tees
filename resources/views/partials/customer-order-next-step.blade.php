@if(auth()->user()?->role === 'customer' && auth()->id() === $order->user_id)
@php
    $receiptClosed = $order->status === 'cancelled' || $order->inventory_released;
    $receiptConfirmed = ! $receiptClosed && $order->payment_status === 'paid' && $order->deliveryConfirmed();
    $receiptDate = $order->customer_confirmed_at ?? $order->walk_in_received_at;
    $receiptCanConfirm = ! $receiptClosed && $order->payment_status === 'paid' && $order->payment_id
        && $order->sales_channel !== 'walk_in' && ! $order->customer_confirmed_at
        && ($order->delivery_method === 'pickup' ? $order->status === 'ready'
            : ($order->delivery_method === 'lalamove' && $order->status === 'out_for_delivery' && $order->provider_completed_at));
@endphp
@if(($receiptLocation ?? 'summary') === 'list')
    @if($receiptCanConfirm)<a class="button small receipt-action" href="{{ route('orders.show', $order) }}#order-receipt">Order received</a>@endif
@elseif($receiptConfirmed)
    <div class="customer-order-next-step receipt-saved" role="status"><strong>Order received</strong>
        @if($receiptDate)<small>Confirmed {{ $receiptDate->format('M d, Y · g:i A') }}.</small>@endif
        <a class="text-link" href="#refunds-returns">Return / refund instructions →</a>
    </div>
@elseif(! $receiptClosed && $order->payment_status === 'paid' && $order->sales_channel !== 'walk_in')
    <div class="customer-order-next-step">
        <a class="button {{ $receiptCanConfirm ? 'dark' : '' }}" href="#order-receipt">{{ $receiptCanConfirm ? 'Order received · confirm here' : 'Order received? View instructions' }}</a>
        <p>{{ $receiptCanConfirm ? 'After receiving and checking your clothing, choose a star rating and confirm receipt.' : ($order->delivery_method === 'pickup' ? 'Receipt confirmation opens when your paid pickup order is ready. Confirm only after collecting your clothing.' : 'Receipt confirmation opens after Lalamove delivery completion is verified. Confirm only after receiving your clothing.') }}</p>
    </div>
@endif
@endif
