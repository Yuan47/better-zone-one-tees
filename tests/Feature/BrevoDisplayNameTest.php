<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\BrevoTransactions;
use App\Services\EmailVerificationCodes;
use App\Services\InvoiceReminders;
use App\Services\OrderFlow;
use App\Services\PasswordRecovery;
use App\Services\StaffAccounts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrevoDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.url' => 'https://shop.zoneone.example',
            'auth.email_verification.required' => true, 'auth.staff_setup.enabled' => true, 'auth.password_recovery.enabled' => true,
            'services.brevo.api_key' => 'mock-name-contract-key', 'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees', 'services.brevo.payment_reminders_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => fn ($request) => mb_strlen($request['sender']['name']) <= 70 && mb_strlen($request['to'][0]['name']) <= 70
                ? Http::response(['messageId' => '<name-contract@brevo.example>'], 201)
                : Http::response(['code' => 'invalid_parameter'], 400)]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public static function recipientFlows(): array
    {
        $cases = [];
        foreach (['verification', 'staff', 'recovery', 'reminder'] as $flow) {
            $cases[$flow.' ASCII'] = [$flow, str_repeat('A', 100)];
            $cases[$flow.' Unicode'] = [$flow, str_repeat('é漢', 50)];
        }

        return $cases;
    }

    #[DataProvider('recipientFlows')]
    public function test_outbound_display_names_fit_the_provider_without_changing_account_names_or_greetings(string $flow, string $name): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer', 'name' => $name]);
        $invitation = null;
        if ($flow === 'verification') {
            $this->assertSame('sent', app(EmailVerificationCodes::class)->send($user));
        } elseif ($flow === 'staff') {
            $admin = User::factory()->create(['role' => 'admin']);
            $service = app(StaffAccounts::class);
            $invitation = $service->create($admin, ['name' => $name, 'email' => 'long-name-staff@example.test',
                'password' => 'InitialStaff!2026', 'password_confirmation' => 'InitialStaff!2026']);
            $this->assertSame('sent', $service->send($admin, $invitation));
        } elseif ($flow === 'recovery') {
            app(PasswordRecovery::class)->request($user->email);
        } else {
            $product = Product::create(['name' => 'Display name fixture', 'slug' => 'display-name-fixture', 'category' => '', 'description' => 'Isolated mail fixture']);
            $variant = $product->variants()->create(['sku' => 'DISPLAY-NAME-WHITE-M', 'size' => 'M', 'color' => 'White', 'stock' => 10,
                'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
            app(OrderFlow::class)->reserve([$variant->id => 5], ['recipient' => 'Reminder buyer', 'phone' => '09123456789',
                'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'payment_plan' => 'partial',
                'due_at' => now()->addDays(3)->setTime(23, 59)], $user->id);
            $this->assertSame(1, app(InvoiceReminders::class)->run()['sent']);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'][0]['name'] === mb_substr($name, 0, 70)
            && mb_check_encoding($request['to'][0]['name'], 'UTF-8')
            && str_contains($request['htmlContent'], $name) && str_contains($request['textContent'], $name));
        $this->assertSame($name, $user->fresh()->name);
        if ($invitation !== null) {
            $this->assertSame($name, $invitation->fresh()->name);
        }
    }

    public static function senderNames(): array
    {
        return ['ASCII 70' => [str_repeat('A', 70), true], 'ASCII 71' => [str_repeat('A', 71), false],
            'Unicode 70' => [str_repeat('漢', 70), true], 'Unicode 71' => [str_repeat('漢', 71), false]];
    }

    #[DataProvider('senderNames')]
    public function test_every_sender_readiness_check_enforces_the_provider_character_limit(string $name, bool $expected): void
    {
        config(['services.brevo.from_name' => $name]);
        foreach ([BrevoTransactions::class, EmailVerificationCodes::class, InvoiceReminders::class] as $service) {
            $this->assertSame($expected, app($service)->configured(), $service);
        }
        $this->assertSame($expected, app(StaffAccounts::class)->ready());
        $this->assertSame($expected, app(PasswordRecovery::class)->ready());
        $result = app(BrevoTransactions::class)->send(['to' => [['email' => 'customer@example.test', 'name' => 'Customer']],
            'subject' => 'Fixture', 'htmlContent' => '<p>Fixture</p>', 'textContent' => 'Fixture']);
        $this->assertSame($expected ? 'sent' : 'unavailable', $result['status']);
        if ($expected) {
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request['sender']['name'] === $name);
        } else {
            Http::assertNothingSent();
        }
    }
}
