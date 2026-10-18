<?php

namespace App\Http\Controllers;

use App\Services\ActivityRecorder;
use App\Services\CustomerSessions;
use App\Services\GoogleSignIn;
use App\Services\GoogleSignInException;
use App\Services\InstallmentCredit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoogleSignInController extends Controller
{
    public function start(Request $request, GoogleSignIn $google)
    {
        if (! $google->ready()) {
            return $this->failure($request, 'Google sign-in is not available yet. Use your email and password.');
        }

        return $this->privateResponse($google->begin($request));
    }

    public function link(Request $request, GoogleSignIn $google)
    {
        abort_unless($request->user()->role === 'customer', 403);
        if (! $google->ready()) {
            return $this->failure($request, 'Google sign-in is not available yet.');
        }
        $request->validate(['current_password' => ['required', 'string', 'max:255', 'current_password:web']]);

        return $this->privateResponse($google->begin($request, true));
    }

    public function callback(Request $request, GoogleSignIn $google)
    {
        if (! $google->ready()) {
            $google->clear($request);

            return $this->failure($request, 'Google sign-in is not available yet. Use your email and password.');
        }
        try {
            $context = $google->consume($request);
            if ($request->query->has('error')) {
                throw new GoogleSignInException($request->query('error') === 'access_denied'
                    ? 'Google sign-in was cancelled. You can try again or use your email and password.'
                    : 'Google sign-in could not be completed. Please try again.');
            }
            $code = $request->query('code');
            if (! is_string($code) || trim($code) === '' || strlen($code) > 4096) {
                throw new GoogleSignInException('Google did not return a valid sign-in response. Please start again.');
            }
            [$user, $created] = $google->account($google->identity($request), $context, $request);
        } catch (GoogleSignInException $exception) {
            return $this->failure($request, $exception->getMessage());
        } finally {
            $google->clear($request);
        }
        if ($context['mode'] === 'link') {
            $request->session()->regenerate(true);

            return $this->privateResponse(redirect()->route('account')->with('notice', 'Google sign-in is now linked to your customer account.'));
        }
        Auth::login($user);
        $request->session()->regenerate(true);
        app(CustomerSessions::class)->start($request);
        ActivityRecorder::mark($request, $created ? 'auth.registered' : 'auth.login', $user);
        if (app(InstallmentCredit::class)->alertsFor($user)->isNotEmpty()) {
            $request->session()->flash('payment_alert', true);
        }
        // Destinations are selected here, never taken from provider or query input.
        $request->session()->forget('url.intended');

        return $this->privateResponse(redirect()->route('store'));
    }

    private function failure(Request $request, string $message)
    {
        return $this->privateResponse(redirect()->route($request->user() ? 'account' : 'login')->withErrors(['google' => $message]));
    }

    private function privateResponse($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
