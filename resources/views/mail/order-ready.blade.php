<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your order is ready</title></head>
<body style="margin:0;background:#ffffff;color:#220901;font-family:Arial,sans-serif;line-height:1.6">
<main style="max-width:600px;margin:0 auto;padding:32px 20px">
<p style="color:#941B0C;font-weight:bold">ZONE ONE TEES</p>
<h1 style="font-size:26px;line-height:1.3">{{ $pickup?'Ready for store pickup':'Ready for delivery' }}</h1>
<p>Hello {{ $name }},</p>
<p>Your fully paid order <strong>{{ $order->number }}</strong> has been packed and checked.</p>
@if($pickup)
<p>Your clothing is ready for collection at the store. Open your order for pickup details.@if($order->sales_channel!=='walk_in') Please confirm receipt and submit your review after collecting your order.@endif</p>
@else
<p>The store will arrange a courier next. This email does not mean a courier has collected your order. Open your order to see the latest delivery status and tracking details when available.</p>
@if($order->sales_channel!=='walk_in')<p>Please confirm receipt and submit your review after your order arrives.</p>@endif
@endif
<p><a href="{{ $orderUrl }}" style="display:inline-block;background:#941B0C;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">View your order</a></p>
<p>Thank you,<br>Zone One Tees</p>
</main></body></html>
