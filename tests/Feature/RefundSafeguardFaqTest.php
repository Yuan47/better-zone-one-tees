<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundSafeguardFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_refund_guidance_is_available_without_ai_and_does_not_invent_approval(): void
    {
        config(['shop.groq.key' => '']);
        Http::preventStrayRequests();
        $this->postJson('/chat', ['message' => 'How are returned clothes checked before a refund?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('mode', 'basic')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'owner records') && str_contains($reply, 'without counting the clothing twice'));
        $this->postJson('/chat', ['message' => 'What is the standard return-request window?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', fn ($reply) => str_contains($reply, '7 days') && str_contains($reply, 'recorded owner exception'));
        $this->postJson('/chat', ['message' => 'Does an approved refund mean the money has arrived?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'does not transfer money') && str_contains($reply, 'Pending or processing'));
        Http::assertNothingSent();
    }

    public function test_migration_is_idempotent_and_preserves_an_owner_edited_or_inactive_answer(): void
    {
        $faq = ChatbotFaq::where('question', 'What is the standard return-request window?')->firstOrFail();
        $faq->update(['answer' => 'Owner-approved guidance remains authoritative.', 'active' => false]);
        $before = ChatbotFaq::count();
        $migration = require database_path('migrations/2026_10_18_000014_add_refund_safeguard_faqs.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before, ChatbotFaq::count());
        $this->assertSame('Owner-approved guidance remains authoritative.', $faq->fresh()->answer);
        $this->assertFalse($faq->fresh()->active);
    }
}
