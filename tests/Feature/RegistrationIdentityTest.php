<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.email_verification.required' => true,
            'services.brevo.api_key' => 'mock-registration-key',
            'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<registration@brevo.example>'], 201)]);
    }

    private function registration(array $extra = []): array
    {
        return $extra + ['name' => 'New Customer', 'username' => 'new_customer', 'email' => 'registration@example.test',
            'password' => 'RegistrationPassword!2026', 'password_confirmation' => 'RegistrationPassword!2026'];
    }

    public static function requiredIdentityFields(): array
    {
        return [['name'], ['username']];
    }

    #[DataProvider('requiredIdentityFields')]
    public function test_each_identity_field_is_required_before_account_creation_or_email(string $field): void
    {
        $data = $this->registration();
        unset($data[$field]);
        $this->post('/register', $data)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public static function invalidIdentityFields(): array
    {
        return [
            'blank full name' => ['name', ''],
            'whitespace full name' => ['name', " \t "],
            'missing full name value' => ['name', null],
            'long full name' => ['name', str_repeat('N', 101)],
            'array full name' => ['name', ['New Customer']],
            'numeric full name' => ['name', 1234],
            'boolean full name' => ['name', true],
            'blank username' => ['username', ''],
            'whitespace username' => ['username', " \t "],
            'missing username value' => ['username', null],
            'short username' => ['username', 'ab'],
            'long username' => ['username', str_repeat('a', 41)],
            'spaced username' => ['username', 'new customer'],
            'email username' => ['username', 'new@example.test'],
            'slash username' => ['username', 'new/customer'],
            'unicode username' => ['username', '新しい顧客'],
            'array username' => ['username', ['new_customer']],
            'numeric username' => ['username', 1234],
            'boolean username' => ['username', true],
        ];
    }

    #[DataProvider('invalidIdentityFields')]
    public function test_invalid_identity_is_rejected_without_an_account_or_verification_email(string $field, mixed $value): void
    {
        $this->post('/register', $this->registration([$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_username_is_unique_after_trimming_and_case_normalization(): void
    {
        $existing = User::factory()->create(['username' => 'new_customer']);
        $this->post('/register', $this->registration(['username' => ' NEW_CUSTOMER ']))->assertSessionHasErrors('username');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('new_customer', $existing->fresh()->username);
        $this->assertDatabaseMissing('users', ['email' => 'registration@example.test']);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_registration_saves_both_identity_fields_and_verifies_only_the_saved_email(): void
    {
        $this->post('/register', $this->registration(['name' => '  Maria Clara Santos  ', 'username' => '  Shop_Owner-1.  ',
            'role' => 'admin', 'email_verified_at' => now()->toDateTimeString(), 'recipient' => 'override@example.test']))
            ->assertRedirect('/email/verify')->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertSame('Maria Clara Santos', $user->name);
        $this->assertSame('shop_owner-1.', $user->username);
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('RegistrationPassword!2026', $user->password));
        $this->assertAuthenticatedAs($user);
        $challenge = EmailVerificationCode::sole();
        $this->assertSame($user->id, $challenge->user_id);
        $this->assertSame($user->email, $challenge->email);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === [['email' => $user->email, 'name' => $user->name]]);
        $request = Http::recorded()->sole()[0];
        $this->assertSame(1, preg_match('/verification code is ([0-9]{6})\./', $request['textContent'], $matches));
        $this->post('/email/verify', ['code' => $matches[1]])->assertRedirect('/');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('shop_owner-1.', $user->fresh()->username);
        Http::assertSentCount(1);
    }

    public static function validUsernameLengths(): array
    {
        return [[3], [40]];
    }

    #[DataProvider('validUsernameLengths')]
    public function test_username_length_boundaries_and_full_name_limit_are_accepted(int $length): void
    {
        config(['auth.email_verification.required' => false]);
        $this->post('/register', $this->registration(['name' => str_repeat('N', 100), 'username' => str_repeat('a', $length)]))
            ->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', $length), User::sole()->username);
        $this->assertSame(100, strlen(User::sole()->name));
        Http::assertNothingSent();
    }

    public static function concurrentUniqueFields(): array
    {
        return [['username'], ['email']];
    }

    #[DataProvider('concurrentUniqueFields')]
    public function test_a_unique_value_claimed_after_validation_returns_a_field_error_without_email(string $field): void
    {
        // Insert a competing account after validation, immediately before this request writes.
        $event = 'eloquent.creating: '.User::class;
        Event::listen($event, function (User $attempt) use ($field) {
            DB::table('users')->insert(['name' => 'Other Customer',
                'username' => $field === 'username' ? $attempt->username : 'other_customer',
                'email' => $field === 'email' ? $attempt->email : 'other@example.test',
                'password' => Hash::make('OtherPassword!2026'), 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            $this->post('/register', $this->registration())->assertSessionHasErrors($field);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Other Customer', User::sole()->name);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertGuest();
        Http::assertNothingSent();
    }
}
