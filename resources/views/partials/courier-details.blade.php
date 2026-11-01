@if($order->delivery_method==='lalamove' && $order->delivery_last_status)
@php($courierState=match($order->delivery_last_status){
    'ASSIGNING_DRIVER'=>'Looking for a rider',
    'ON_GOING'=>'Rider assigned',
    'PICKED_UP'=>'Clothing picked up by the rider',
    'COMPLETED'=>'Courier marked delivery complete',
    'CANCELED'=>'Previous courier booking canceled',
    'REJECTED'=>'Previous courier booking rejected',
    'EXPIRED'=>'Previous courier booking expired',
    default=>'Status needs checking',
})
<details class="courier-details"><summary>Lalamove delivery details</summary>
<p><strong>Last confirmed courier status:</strong> {{ $courierState }}.</p>
@if($order->delivery_last_status==='COMPLETED' && !$order->deliveryConfirmed())
<p>Confirm receipt and choose a rating to close this order. A written review is optional. The courier's update alone does not mark it Delivered.</p>
@elseif(in_array($order->delivery_last_status,['CANCELED','REJECTED','EXPIRED']))
<p>The store must arrange another courier. Your payment stays on the same invoice.</p>
@else
<p>Courier updates are checked automatically. Refresh this order to see the latest saved status.</p>
@endif
@if(in_array(auth()->user()?->role,['staff','admin']) && $order->delivery_id)
<p>Courier booking reference: <span>{{ $order->delivery_id }}</span></p>
@if(is_int(data_get($order->delivery_booking_context,'quoted_cost')))
<p>Booking quote: ₱{{ number_format($order->delivery_booking_context['quoted_cost']/100,2) }} · Customer delivery fee: ₱{{ number_format($order->shipping/100,2) }}.</p>
@endif
@if(data_get($order->delivery_booking_context,'store_cost_approval'))
<p>Owner-approved maximum courier cost: ₱{{ number_format(data_get($order->delivery_booking_context,'store_cost_approval.max_total')/100,2) }}. The store covers any difference above the customer's original delivery fee.</p>
@endif
@endif
</details>
@endif
@if(is_string($order->tracking_url) && filter_var($order->tracking_url,FILTER_VALIDATE_URL) && parse_url($order->tracking_url,PHP_URL_SCHEME)==='https' && in_array(parse_url($order->tracking_url,PHP_URL_HOST),['share.lalamove.com','share.sandbox.lalamove.com'],true))
<a class="button" href="{{ $order->tracking_url }}" target="_blank" rel="noopener noreferrer">Track delivery ↗</a>
@endif
