<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordInputTest extends TestCase
{
    use RefreshDatabase;

    public static function unsafePasswords(): array
    {
        return [[str_repeat('A', 73)], [str_repeat('界', 25)], ["Password\0Rejected!2026"]];
    }

    #[DataProvider('unsafePasswords')]
    public function test_registration_rejects_unhashable_or_truncated_passwords_without_creating_an_account(string $password): void
    {
        $this->post('/register', ['name' => 'New Customer', 'username' => 'new_customer', 'email' => 'new-password@example.test',
            'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->assertDatabaseMissing('users', ['email' => 'new-password@example.test']);
    }

    #[DataProvider('unsafePasswords')]
    public function test_password_change_validation_preserves_the_existing_password(string $password): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword!2026']);
        $this->actingAs($user)->patch('/account/password', ['current_password' => 'OriginalPassword!2026',
            'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->assertTrue(Hash::check('OriginalPassword!2026', $user->fresh()->password));
    }
}
