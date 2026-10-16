<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoice payment reminder</title></head>
<body style="margin:0;background:#ffffff;color:#220901;font-family:Arial,sans-serif;line-height:1.6">
<main style="max-width:600px;margin:0 auto;padding:32px 20px">
<p style="color:#941B0C;font-weight:bold">ZONE ONE TEES</p>
<h1 style="font-size:26px;line-height:1.3">Your invoice is due in {{ $days }} {{ $days===1?'day':'days' }}</h1>
<p>Hello {{ $name }},</p>
<p>This is a reminder about the remaining balance on invoice <strong>{{ $order->number }}</strong>.</p>
<table style="width:100%;border-collapse:collapse;text-align:left"><tr><th style="padding:12px 0;border-bottom:1px solid #eee">Remaining balance</th><td style="padding:12px 0;border-bottom:1px solid #eee;text-align:right">₱{{ number_format($amount/100,2) }}</td></tr><tr><th style="padding:12px 0">Payment due date</th><td style="padding:12px 0;text-align:right">{{ $dueLabel }}<br><small>Asia/Manila</small></td></tr></table>
<p><a href="{{ $invoiceUrl }}" style="display:inline-block;background:#941B0C;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">View your invoice</a></p>
<p>If you have made a payment that is still being verified, open your invoice to check its latest status. This reminder does not change your invoice or payment deadline.</p>
<p>Thank you,<br>Zone One Tees</p>
</main></body></html>
