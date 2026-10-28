<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BrevoTransactions
{
    public const DISPLAY_NAME_LIMIT = 70;

    public function configured(): bool
    {
        $key = config('services.brevo.api_key');
        $email = config('services.brevo.from_email');
        $name = config('services.brevo.from_name');

        return is_string($key) && trim($key) !== '' && ! preg_match('/[\r\n]/', $key)
            && is_string($email) && strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false
            && is_string($name) && trim($name) !== '' && mb_strlen(trim($name)) <= self::DISPLAY_NAME_LIMIT && ! preg_match('/[\r\n]/', $name);
    }

    public static function recipientName(string $name): string
    {
        return mb_substr(trim($name), 0, self::DISPLAY_NAME_LIMIT, 'UTF-8');
    }

    public function send(#[\SensitiveParameter] array $payload): array
    {
        $result = ['status' => 'unavailable', 'provider_status' => null, 'message_id' => null];
        if (! $this->configured()) {
            return $result;
        }
        foreach ($payload['to'] ?? [] as $index => $recipient) {
            if (isset($recipient['name'])) {
                $payload['to'][$index]['name'] = self::recipientName($recipient['name']);
            }
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
        // Brevo's duplicate idempotency response can mean the first request was accepted.
        // It is not proof that no email was sent and must not authorize a resend.
        if ($response->status() === 400 && $response->json('code') === 'duplicate_parameter') {
            return ['status' => 'unknown', 'provider_status' => 400, 'message_id' => null];
        }
        $message = $response->json('messageId');
        if ($response->status() === 201 && is_string($message) && trim($message) !== '' && strlen($message) <= 255) {
            return ['status' => 'sent', 'provider_status' => 201, 'message_id' => $message];
        }

        return ['status' => $response->status() >= 400 && $response->status() < 500 ? 'failed' : 'unknown',
            'provider_status' => $response->status(), 'message_id' => null];
    }
}
