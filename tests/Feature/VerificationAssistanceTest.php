<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerificationAssistanceTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_unverified_accounts_receive_clear_help_without_opening_private_chat(string $role): void
    {
        Http::preventStrayRequests();
        config(['auth.email_verification.required' => true]);
        $user = User::factory()->create(['role' => $role, 'email_verified_at' => null]);
        $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee('Verification help')
            ->assertSee('Open email verification')->assertDontSee('id="chat-form"', false);
        $this->postJson('/chat', ['message' => 'Show customer orders', 'side' => 'staff'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_guest_and_verified_customer_keep_the_normal_assistant(): void
    {
        Http::preventStrayRequests();
        config(['auth.email_verification.required' => true]);
        $this->get('/')->assertOk()->assertSee('id="chat-form"', false)->assertDontSee('data-verification-help-link', false);
        $this->actingAs(User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]))
            ->get('/')->assertOk()->assertSee('id="chat-form"', false)->assertDontSee('data-verification-help-link', false);
        Http::assertNothingSent();
    }
}
