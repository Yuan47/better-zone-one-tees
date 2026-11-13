<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountTermsConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.email_verification.required' => true,
            'services.brevo.api_key' => 'isolated-terms-key',
            'services.brevo.from_email' => 'accounts@example.test',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<terms@example.test>'], 201)]);
    }

    private function registration(array $overrides = []): array
    {
        return $overrides + ['name' => 'New Customer', 'username' => 'new_customer', 'email' => 'customer@gmail.com',
            'password' => 'RegistrationPassword!2026', 'password_confirmation' => 'RegistrationPassword!2026', 'terms' => '1'];
    }

    public static function missingConsent(): array
    {
        return [['missing'], ['0'], [false], [null], [['1']]];
    }

    #[DataProvider('missingConsent')]
    public function test_signup_rejects_missing_or_unaccepted_terms_without_creating_accounts_or_sending_mail(mixed $terms): void
    {
        $data = $this->registration(['terms' => $terms]);
        if ($terms === 'missing') {
            unset($data['terms']);
        }
        $this->post('/register', $data)->assertSessionHasErrors('terms');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
    }

    public function test_signup_records_current_terms_without_trusting_submitted_acceptance_dates_or_skipping_email_verification(): void
    {
        $this->post('/register', $this->registration(['terms_version' => 'attacker-version',
            'terms_accepted_at' => '2000-01-01 00:00:00', 'email_verified_at' => now()->toDateTimeString()]))
            ->assertRedirect('/email/verify')->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertSame(StoreTerms::VERSION, $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertTrue($user->terms_accepted_at->greaterThan(now()->subMinute()));
        $this->assertNull($user->email_verified_at);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === [['email' => 'customer@gmail.com', 'name' => 'New Customer']]);
        $this->get('/checkout')->assertRedirect('/email/verify');
    }

    public static function validEmailProviders(): array
    {
        return [['customer@gmail.com'], ['customer@yahoo.com'], ['customer@outlook.com'], ['customer@custom-shop.example']];
    }

    #[DataProvider('validEmailProviders')]
    public function test_well_formed_mail_provider_addresses_still_require_ownership_verification(string $email): void
    {
        $this->post('/register', $this->registration(['email' => $email]))->assertRedirect('/email/verify');
        $user = User::sole();
        $this->assertNull($user->email_verified_at);
        $this->assertSame($email, $user->email);
        Http::assertSent(fn ($request) => $request['to'] === [['email' => $email, 'name' => 'New Customer']]);
    }

    public static function mistypedEmailProviders(): array
    {
        return [['customer@gmial.com'], ['customer@gmail.con'], ['customer@yaho.com'], ['customer@outlok.com'], ['customer@localhost']];
    }

    #[DataProvider('mistypedEmailProviders')]
    public function test_common_provider_misspellings_are_rejected_before_account_creation_or_email(string $email): void
    {
        $this->post('/register', $this->registration(['email' => $email]))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_registration_displays_required_terms_and_public_policy_explains_payment_delivery_and_refunds(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="terms" value="1" required', false)->assertSee(route('terms'), false);
        $this->get('/terms')->assertOk()->assertSee('PayMongo')->assertSee('Lalamove')->assertSee('refund');
    }
}
