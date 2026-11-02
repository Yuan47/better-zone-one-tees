<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundPhotoAndReviewHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_photo_rule_is_grounded_even_when_an_old_faq_says_optional(): void
    {
        config(['shop.groq.key' => 'fake-test-key', 'shop.groq.model' => 'fake-test-model']);
        Http::preventStrayRequests();
        ChatbotFaq::where('question', 'Is a photo required for a refund or return request?')
            ->update(['answer' => 'An outdated answer says an optional photo is enough.']);

        $this->postJson('/chat', ['message' => 'Is a photo required for a refund or return request?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', StoreKnowledge::REFUND_PHOTO_HELP)
            ->assertJsonPath('mode', 'store');
        Http::assertNothingSent();
    }

    public function test_product_review_guide_explains_order_rating_and_keeps_refund_evidence_private(): void
    {
        config(['shop.groq.key' => '']);
        Http::preventStrayRequests();
        $this->postJson('/chat', ['message' => 'Where can I see customer ratings and reviews for an item?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('mode', 'basic')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'whole order')
                && str_contains($reply, 'stars only') && str_contains($reply, 'private refund photos are not published'));
        Http::assertNothingSent();
    }

    public function test_guidance_refresh_is_idempotent_and_preserves_owner_edits_and_audiences(): void
    {
        $faq = ChatbotFaq::where('question', 'How do I ask for a refund or return?')->firstOrFail();
        $faq->update(['answer' => 'Custom owner-approved instructions.', 'active' => false, 'audience' => 'owner', 'sort_order' => 42]);
        $photo = ChatbotFaq::where('question', 'Is a photo required for a refund or return request?')->firstOrFail();
        $photo->update(['answer' => 'Custom photo guidance.', 'active' => false]);
        $before = ChatbotFaq::count();
        $migration = require database_path('migrations/2026_11_02_000025_add_refund_photo_and_product_review_guides.php');
        $migration->up();
        $migration->up();

        $this->assertSame($before, ChatbotFaq::count());
        $this->assertSame('Custom owner-approved instructions.', $faq->fresh()->answer);
        $this->assertFalse($faq->fresh()->active);
        $this->assertSame('owner', $faq->fresh()->audience);
        $this->assertSame(42, $faq->fresh()->sort_order);
        $this->assertSame('Custom photo guidance.', $photo->fresh()->answer);
        $this->assertFalse($photo->fresh()->active);
    }
}
