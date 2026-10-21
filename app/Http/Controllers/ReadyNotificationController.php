<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ReadyOrderNotifications;
use Illuminate\Http\Request;

class ReadyNotificationController extends Controller
{
    public function retry(Request $request, Order $order, ReadyOrderNotifications $notifications)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        $status = $notifications->send($order->id, $request->user(), $data['reason']);

        return back()->with('notice', match ($status) {
            'sent' => 'Brevo accepted the customer readiness email. Inbox delivery is not yet confirmed.',
            'failed' => 'The email provider rejected this attempt. Check email setup before retrying.',
            'unavailable' => 'Customer readiness emails are disabled or the email connection is incomplete.',
            'unknown', 'review' => 'The email outcome needs review. Do not resend until it is confirmed with Brevo.',
            default => 'No email was sent. The order, recipient or previous attempt is not eligible for a safe retry.',
        });
    }
}
