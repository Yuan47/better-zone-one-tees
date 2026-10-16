<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function sessionDrivers(): array
    {
        return [['array'], ['file'], ['database']];
    }

    #[DataProvider('sessionDrivers')]
    public function test_real_login_cannot_keep_using_a_password_that_changed_in_another_session(string $driver): void
    {
        config(['session.driver' => $driver]);
        $user = User::factory()->create(['password' => 'OriginalPassword!2026']);
        $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword!2026'])->assertRedirect('/');
        $this->get('/account')->assertOk();
        $this->assertNotEmpty(session('password_hash_web'));

        // A separate model represents a recovery or change made in a different browser.
        $otherBrowser = User::findOrFail($user->id);
        $otherBrowser->update(['password' => 'ReplacementPassword!2026']);
        Auth::forgetGuards();
        $this->get('/account')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(Hash::check('ReplacementPassword!2026', $user->fresh()->password));
    }

    public function test_a_real_password_change_keeps_the_current_session_usable(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword!2026']);
        $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword!2026'])->assertRedirect('/');
        $this->patch('/account/password', ['current_password' => 'OriginalPassword!2026',
            'password' => 'ReplacementPassword!2026', 'password_confirmation' => 'ReplacementPassword!2026'])->assertRedirect('/account');
        Auth::forgetGuards();
        $this->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
