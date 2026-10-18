<?php

namespace App\Services;

use Laravel\Socialite\Two\GoogleProvider;

class GoogleOAuthProvider extends GoogleProvider
{
    public function getAccessTokenResponse($code)
    {
        $response = parent::getAccessTokenResponse($code);
        if (! is_array($response) || ! is_string($response['access_token'] ?? null)
            || trim($response['access_token']) === '' || strlen($response['access_token']) > 8192
            || ! is_string($response['token_type'] ?? null) || strtolower($response['token_type']) !== 'bearer'
            || ! is_int($response['expires_in'] ?? null) || $response['expires_in'] <= 0
            || isset($response['error'])) {
            throw new GoogleSignInException('Google sign-in could not be verified. Please try again.');
        }

        return $response;
    }
}
