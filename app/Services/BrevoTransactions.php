<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BrevoTransactions
{
    public function configured(): bool
    {
        $key = config('services.brevo.api_key');
        $email = config('services.brevo.from_email');
        $name = config('services.brevo.from_name');

        return is_string($key) && trim($key) !== '' && ! preg_match('/[\r\n]/', $key)
            && is_string($email) && strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false
            && is_string($name) && trim($name) !== '' && mb_strlen($name) <= 100 && ! preg_match('/[\r\n]/', $name);
    }

    public function send(#[\SensitiveParameter] array $payload): array
    {
        $result = ['status' => 'unavailable', 'provider_status' => null, 'message_id' => null];
        if (! $this->configured()) {
            return $result;
        }
        try {
            $response = Http::withHeaders(['api-key' => trim(config('services.brevo.api_key'))])->acceptJson()->asJson()
                ->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => ['name' => trim(config('services.brevo.from_name')), 'email' => trim(config('services.brevo.from_email'))],
                ] + $payload);
        } catch (\Throwable $exception) {
            // Acceptance may be uncertain. Never retry or log payloads containing codes or reset links.
            return ['status' => 'unknown', 'provider_status' => null, 'message_id' => null];
        }
        $message = $response->json('messageId');
        if ($response->status() === 201 && is_string($message) && trim($message) !== '' && strlen($message) <= 255) {
            return ['status' => 'sent', 'provider_status' => 201, 'message_id' => $message];
        }

        return ['status' => $response->status() >= 400 && $response->status() < 500 ? 'failed' : 'unknown',
            'provider_status' => $response->status(), 'message_id' => null];
    }
}
