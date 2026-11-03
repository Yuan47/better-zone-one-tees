<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimpleVariantGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_guide_explains_the_separate_styles_and_normal_color_list(): void
    {
        config(['shop.groq.key' => '']);
        Http::preventStrayRequests();
        $this->postJson('/chat', ['message' => 'How do I choose a Dannon neckline and color?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('mode', 'basic')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'separate Dannon Round Neck')
                && str_contains($reply, 'White is in the same color list'));
        Http::assertNothingSent();
    }

    public function test_owner_product_guide_removes_group_selection_and_explains_zero_counted_stock(): void
    {
        $guide = ChatbotFaq::where('question', 'How does the owner add a product and its size and color variants?')->firstOrFail();
        $this->assertSame('owner', $guide->audience);
        $this->assertStringContainsString('no White/Colored group selection is needed', $guide->answer);
        $this->assertStringContainsString('new color variants start at zero stock', $guide->answer);
    }

    public function test_staff_review_guide_is_available_in_the_workspace_but_not_customer_context(): void
    {
        config(['shop.groq.key' => '']);
        Http::preventStrayRequests();
        $question = 'How can staff and the owner read customer product reviews?';
        $guide = ChatbotFaq::where('question', $question)->firstOrFail();
        foreach (['staff', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson('/chat', ['message' => $question, 'side' => 'staff'])
                ->assertOk()->assertJsonPath('reply', $guide->answer);
        }
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/chat', ['message' => $question, 'side' => 'customer'])
            ->assertOk()->assertDontSee('The review page is read-only');
        Http::assertNothingSent();
    }

    public function test_migration_preserves_owner_edits_inactive_entries_audiences_and_order(): void
    {
        $guide = ChatbotFaq::where('question', 'How does the owner add a product and its size and color variants?')->firstOrFail();
        $guide->update(['answer' => 'Custom product instructions.', 'active' => false, 'sort_order' => 7]);
        $review = ChatbotFaq::where('question', 'How can staff and the owner read customer product reviews?')->firstOrFail();
        $review->update(['answer' => 'Custom staff instructions.', 'active' => false, 'audience' => 'owner']);
        $count = ChatbotFaq::count();
        $migration = require database_path('migrations/2026_11_03_000027_add_simple_variant_and_review_workspace_guides.php');
        $migration->up();
        $migration->up();
        $this->assertSame($count, ChatbotFaq::count());
        $this->assertSame('Custom product instructions.', $guide->fresh()->answer);
        $this->assertFalse($guide->fresh()->active);
        $this->assertSame(7, $guide->fresh()->sort_order);
        $this->assertSame('Custom staff instructions.', $review->fresh()->answer);
        $this->assertFalse($review->fresh()->active);
        $this->assertSame('owner', $review->fresh()->audience);
    }
}
