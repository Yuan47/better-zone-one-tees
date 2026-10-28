<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Zone One Tees payment receipt</title></head>
<body style="margin:0;background:#ffffff;color:#220901;font-family:Arial,sans-serif;line-height:1.6">
<main style="max-width:600px;margin:0 auto;padding:32px 20px">
<p style="color:#941B0C;font-weight:bold">ZONE ONE TEES</p>
@if($isTest)<p style="background:#F6AA1C;color:#220901;padding:12px;font-weight:bold">TEST PAYMENT ONLY — NO REAL MONEY MOVED</p>@endif
<h1 style="font-size:26px;line-height:1.3">Your store payment receipt</h1>
<p>Hello {{ $name }},</p>
<p>We recorded your verified online payment of <strong>₱{{ number_format($notification->amount/100,2) }}</strong> for invoice <strong>{{ $order->number }}</strong>.</p>
<dl><dt>Store receipt reference</dt><dd style="margin:0 0 12px;word-break:break-all">{{ $notification->receipt_reference }}</dd>
<dt>Payment received</dt><dd style="margin:0 0 12px">{{ $notification->received_at->copy()->setTimezone('Asia/Manila')->format('F j, Y · g:i A') }} (Asia/Manila)</dd>
<dt>Original invoice total</dt><dd style="margin:0 0 12px">₱{{ number_format($order->total/100,2) }}</dd>
<dt>Balance after this payment</dt><dd style="margin:0 0 12px">₱{{ number_format($notification->balance_after/100,2) }}</dd></dl>
<p>Each installment has its own receipt reference on the same original invoice. Receiving another payment does not extend its recorded deadline.</p>
<p>This is a store-issued payment acknowledgment. It is separate from PayMongo’s email and any tax invoice. Later payments, refunds and the latest invoice status remain available on your account or authorized invoice page.</p>
<p><a href="{{ $invoiceUrl }}" style="display:inline-block;background:#941B0C;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">View your invoice and payment history</a></p>
<p>Thank you,<br>Zone One Tees</p>
</main></body></html>
