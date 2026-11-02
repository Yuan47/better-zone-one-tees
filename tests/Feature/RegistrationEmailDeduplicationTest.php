<?php

namespace Tests\Feature;

use App\Models\GoogleIdentity;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationEmailDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.email_verification.required' => true,
            'services.brevo.api_key' => 'synthetic-registration-key',
            'services.brevo.from_email' => 'accounts@example.test',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<synthetic-registration@example.test>'], 201)]);
    }

    private function registration(string $email): array
    {
        return ['name' => 'New Customer', 'username' => 'new_customer', 'email' => $email,
            'password' => 'RegistrationPassword!2026', 'password_confirmation' => 'RegistrationPassword!2026'];
    }

    public function test_manual_registration_uses_the_same_normalized_email_as_google_and_sends_otp_to_that_address(): void
    {
        $this->post('/register', $this->registration('  Customer@Gmail.Com  '))
            ->assertRedirect('/email/verify')->assertSessionHasNoErrors();

        $user = User::sole();
        $this->assertSame('customer@gmail.com', $user->email);
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id, 'email' => 'customer@gmail.com']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === [['email' => 'customer@gmail.com', 'name' => 'New Customer']]);
    }

    public static function duplicateEmails(): iterable
    {
        foreach (['customer@gmail.com', 'Customer@Gmail.Com'] as $stored) {
            foreach (['customer', 'staff', 'admin'] as $role) {
                yield $role.' '.$stored => [$stored, $role];
            }
        }
    }

    #[DataProvider('duplicateEmails')]
    public function test_manual_signup_cannot_duplicate_or_change_an_existing_email_account(string $stored, string $role): void
    {
        $existing = User::factory()->create(['email' => $stored, 'role' => $role]);
        if ($role === 'customer') {
            GoogleIdentity::create(['user_id' => $existing->id, 'subject' => 'synthetic-google-customer']);
        }
        $before = $existing->fresh()->getAttributes();

        $this->post('/register', $this->registration('  CUSTOMER@GMAIL.COM  '))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('google_identities', $role === 'customer' ? 1 : 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
    }

    public function test_customer_signup_cannot_claim_an_email_reserved_for_owner_created_staff_setup(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $setup = StaffInvitation::create(['name' => 'Pending Staff', 'email' => 'Customer@Gmail.Com',
            'created_by' => $owner->id, 'password_hash' => Hash::make('StaffPassword!2026')]);
        $before = $setup->fresh()->getAttributes();

        $this->post('/register', $this->registration(' CUSTOMER@GMAIL.COM '))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $setup->fresh()->getAttributes());
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
    }

    public function test_a_google_account_claimed_after_manual_validation_still_blocks_the_second_account(): void
    {
        $event = 'eloquent.creating: '.User::class;
        Event::listen($event, function (User $attempt) {
            $id = DB::table('users')->insertGetId(['name' => 'Google Customer', 'email' => $attempt->email,
                'role' => 'customer', 'password' => Hash::make('UnrelatedRandomPassword!2026'),
                'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('google_identities')->insert(['user_id' => $id, 'subject' => 'concurrent-google-claim',
                'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            $this->post('/register', $this->registration(' Customer@Gmail.Com '))->assertSessionHasErrors('email');
        } finally {
            Event::forget($event);
        }

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('customer@gmail.com', User::sole()->email);
        $this->assertSame('Google Customer', User::sole()->name);
        $this->assertDatabaseHas('google_identities', ['user_id' => User::sole()->id, 'subject' => 'concurrent-google-claim']);
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
    }
}
