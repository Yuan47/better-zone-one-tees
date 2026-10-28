<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissingPaymentReceiptFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_receipt_guidance_uses_the_approved_answer_without_ai_or_exposing_private_status(): void
    {
        config(['shop.groq.key' => 'synthetic-groq-key', 'shop.groq.model' => 'synthetic-model']);
        Http::preventStrayRequests();
        $faq = ChatbotFaq::where('question', 'What should I do if my payment receipt email is missing?')->firstOrFail();
        $this->assertSame('customer', $faq->audience);
        $this->assertStringContainsString('Never pay again', $faq->answer);
        $this->assertStringContainsString('separate acknowledgment', $faq->answer);
        foreach ([$faq->question, 'My PayMongo receipt email is missing for ZOT-261028-ABCD1234'] as $message) {
            $this->postJson(route('chat'), ['message' => $message, 'side' => 'customer'])
                ->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', $faq->answer);
        }
        Http::assertNothingSent();
    }

    public function test_receipt_faq_migration_preserves_owner_edits_inactivity_and_audience_on_repeat_or_rollback(): void
    {
        $faq = ChatbotFaq::where('question', 'What should I do if my payment receipt email is missing?')->firstOrFail();
        $faq->update(['answer' => 'Owner-approved edited receipt guidance.', 'keywords' => 'owner receipt rules', 'active' => false, 'audience' => 'owner']);
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_28_000020_add_missing_payment_receipt_faq.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }
}
