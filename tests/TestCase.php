<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        // Laravel's real SessionGuard::login stamps the password. Match that when a test switches accounts.
        $driver = $guard ?? config('auth.defaults.guard');
        $authGuard = $this->app['auth']->guard($driver);
        $this->withSession(['password_hash_'.$driver => $authGuard->hashPasswordForCookie($user->getAuthPassword())]);

        return $this;
    }
}
