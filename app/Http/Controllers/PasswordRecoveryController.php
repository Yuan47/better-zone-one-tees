<?php

namespace App\Http\Controllers;

use App\Rules\AccountPasswordSize;
use App\Services\PasswordRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryController extends Controller
{
    public function requestForm(PasswordRecovery $recovery)
    {
        return $this->protect(response()->view('auth.forgot-password', ['recoveryReady' => $recovery->ready()]));
    }

    public function send(Request $request, PasswordRecovery $recovery)
    {
        $validator = Validator::make($request->only('email'), ['email' => 'required|string|email|max:254']);
        if ($validator->fails()) {
            return $this->protect(redirect()->route('password.request')->withErrors($validator)->withInput($request->only('email')));
        }
        $recovery->request($validator->validated()['email']);

        return $this->protect(redirect()->route('password.request')->with('notice', PasswordRecovery::NOTICE));
    }

    public function resetForm(Request $request, string $token, PasswordRecovery $recovery)
    {
        $token = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1 ? $token : '';
        $email = $request->query('email');
        $email = is_string($email) && strlen($email) <= 254 ? $email : '';

        return $this->resetResponse($token, $email, $recovery->enabled());
    }

    public function update(Request $request, PasswordRecovery $recovery)
    {
        // Manually render validation failures: reset tokens and passwords must never enter old input or session flashes.
        $validator = Validator::make($request->only('token', 'email', 'password', 'password_confirmation'), [
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'email' => 'required|string|email|max:254',
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(10), new AccountPasswordSize],
        ], [
            'token.required' => PasswordRecovery::INVALID_LINK,
            'token.string' => PasswordRecovery::INVALID_LINK,
            'token.regex' => PasswordRecovery::INVALID_LINK,
            'email.required' => PasswordRecovery::INVALID_LINK,
            'email.string' => PasswordRecovery::INVALID_LINK,
            'email.email' => PasswordRecovery::INVALID_LINK,
            'email.max' => PasswordRecovery::INVALID_LINK,
        ]);

        $token = $request->input('token');
        $token = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1 ? $token : '';
        $email = $request->input('email');
        $email = is_string($email) && strlen($email) <= 254 ? $email : '';
        if ($validator->fails()) {
            return $this->resetResponse($token, $email, $recovery->enabled(), $validator->errors(), 422);
        }
        if (! $recovery->reset($validator->validated())) {
            return $this->resetResponse($token, $email, $recovery->enabled(), new MessageBag(['email' => PasswordRecovery::INVALID_LINK]), 422);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->protect(redirect()->route('login')->with('notice', 'Your password has been reset. Sign in with your new password.'));
    }

    private function resetResponse(#[\SensitiveParameter] string $token, string $email, bool $enabled, ?MessageBag $errors = null, int $status = 200)
    {
        $data = ['resetToken' => $token, 'resetEmail' => $email, 'recoveryEnabled' => $enabled];
        if ($errors !== null) {
            $data['errors'] = (new ViewErrorBag)->put('default', $errors);
        }

        return $this->protect(response()->view('auth.reset-password', $data, $status));
    }

    private function protect($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
