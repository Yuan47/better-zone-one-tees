<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset your password</title></head>
<body style="margin:0;background:#ffffff;color:#220901;font-family:Arial,sans-serif;line-height:1.6">
<main style="max-width:600px;margin:0 auto;padding:32px 20px">
<p style="color:#941B0C;font-weight:bold">ZONE ONE TEES</p>
<h1 style="font-size:26px;line-height:1.3">Reset your password</h1>
<p>Hello {{ $name }},</p>
<p>We received a request to reset your Zone One Tees password.</p>
<p style="margin:28px 0"><a href="{{ $url }}" style="display:inline-block;background:#941B0C;color:#ffffff;padding:14px 22px;border-radius:8px;text-decoration:none;font-weight:bold" rel="noreferrer">Choose a new password</a></p>
<p>This link expires in <strong>60 minutes</strong> and can only be used once. A newer reset email replaces this link.</p>
<p>If the button does not work, copy this address into your browser:</p>
<p style="word-break:break-all"><a href="{{ $url }}" rel="noreferrer">{{ $url }}</a></p>
<p>If you did not request a password reset, ignore this email. Your password will stay the same.</p>
<p>Thank you,<br>Zone One Tees</p>
</main></body></html>
