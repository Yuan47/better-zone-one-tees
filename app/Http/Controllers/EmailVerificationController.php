<?php

namespace App\Http\Controllers;

use App\Services\EmailVerificationCodes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public function show(Request $request, EmailVerificationCodes $codes)
    {
        return response()->view('email-verification', [
            'user' => $request->user(), 'verification' => $codes->state($request->user()),
            'verificationRequired' => $codes->required(), 'verificationReady' => $codes->ready(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
    }

    public function send(Request $request, EmailVerificationCodes $codes)
    {
        $status = $codes->send($request->user());

        return redirect()->route('verification.notice')->with('notice', self::sendMessage($status));
    }

    public function verify(Request $request, EmailVerificationCodes $codes)
    {
        // Never flash the submitted code to session storage on validation failure.
        $code = $request->input('code');
        if (! is_string($code) || ! preg_match('/^[0-9]{6}$/D', $code)) {
            return redirect()->route('verification.notice')->withErrors(['code' => 'Enter the six-digit code from your email.']);
        }
        try {
            $accepted = $codes->verify($request->user(), $code);
        } catch (ValidationException $exception) {
            return redirect()->route('verification.notice')->withErrors($exception->errors());
        }
        if (! $accepted) {
            return redirect()->route('verification.notice')->withErrors(['code' => 'The code is invalid or expired. Request a new code if needed.']);
        }
        $request->user()->refresh();
        $request->session()->regenerate();
        $returnTo = $request->session()->pull('email_verification.return_to');
        $fallback = $request->user()->role === 'customer' ? route('store') : route('admin.dashboard');
        if (! is_string($returnTo) || ! str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')
            || preg_match('/[\\\\\r\n]/', $returnTo) || parse_url($returnTo, PHP_URL_HOST) !== null
            || preg_match('#^/(email(?:/|$)|login(?:/|$)|register(?:/|$)|logout(?:/|$))#i', rawurldecode($returnTo))) {
            $returnTo = $fallback;
        }

        return redirect($returnTo)->with('notice', 'Your email is verified. You can continue.');
    }

    public static function sendMessage(string $status): string
    {
        return match ($status) {
            'sent' => 'Your verification email was accepted for sending. Check your inbox and spam folder.',
            'already_verified' => 'Your email is already verified.',
            'cooldown' => 'Please wait before requesting another code.',
            'limited' => 'You have reached the hourly code limit. Please try again later.',
            'unknown' => 'The email service has not confirmed sending. If a code arrives, you can use it; otherwise request a new one after the waiting time.',
            'failed' => 'The email could not be sent. Please try again after the waiting time or contact the store.',
            default => 'Email verification is waiting for the store’s email setup. Please contact the store if you need help.',
        };
    }
}
