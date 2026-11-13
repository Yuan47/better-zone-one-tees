<div class="purchase-table-scroll">
    <table class="purchase-table">
        <thead><tr><th scope="col">Order</th><th scope="col">Items</th><th scope="col">Total</th><th scope="col">Payment</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        @foreach($purchaseOrders as $order)
            @php($invoiceClosed = $order->status === 'cancelled' || $order->inventory_released)
            <tr>
                <td data-label="Order"><a class="text-link" href="{{ route('orders.show', $order) }}">{{ $order->number }}</a><small>{{ $order->created_at->format('M d, Y') }} · {{ $order->sales_channel === 'walk_in' ? 'Walk-in' : 'Online' }}</small></td>
                <td data-label="Items"><span class="purchase-product-names">{{ $order->items->pluck('name')->unique()->join(', ') ?: 'Order details' }}</span><small>{{ number_format($order->quantity) }} {{ $order->quantity === 1 ? 'piece' : 'pieces' }}@if($order->items->count()) · {{ $order->items->map(fn ($item) => $item->size.' / '.$item->color)->unique()->take(2)->join(', ') }}@endif</small></td>
                <td data-label="Total" class="purchase-money">₱{{ number_format($order->total / 100, 2) }}@if($invoiceClosed)<small>Original invoice</small>@endif</td>
                <td data-label="Payment"><span>{{ $order->payment_status === 'paid' ? 'Fully paid' : ($invoiceClosed ? 'Closed invoice' : 'Payment due') }}</span><small>Payment received ₱{{ number_format($order->paidAmount() / 100, 2) }}</small>
                    @if($invoiceClosed)<small>No payment due</small>@elseif($order->outstandingBalance() > 0)<small class="purchase-balance">Outstanding: ₱{{ number_format($order->outstandingBalance() / 100, 2) }}</small>@if($order->due_at)<small>Due {{ $order->due_at->format('M d, Y') }}{{ $order->invoiceOverdue() ? ' · Overdue' : '' }}</small>@endif @endif
                </td>
                <td data-label="Status"><span class="badge {{ $order->customerDeliveryBadgeClass() }}">{{ $invoiceClosed ? 'Cancelled' : ($order->payment_status === 'paid' ? $order->customerDeliveryLabel() : 'To Pay') }}</span>@if(($order->refunds_count ?? 0) > 0)<small>Return / refund on record</small>@endif</td>
                <td data-label="Details"><a class="button small" href="{{ route('orders.show', $order) }}">View order <span aria-hidden="true">→</span></a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
