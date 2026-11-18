<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadyWorkflowGuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_installation_guides_describe_automatic_marking_and_preserve_their_audience(): void
    {
        $migration = require database_path('migrations/2026_11_18_000034_simplify_ready_workflow_guides.php');
        foreach ($migration::REFRESH as $change) {
            $faq = ChatbotFaq::where('question', $change['question'])->sole();
            $this->assertSame($change['answer'], $faq->answer);
            $this->assertSame('staff', $faq->audience);
            $this->assertTrue($faq->active);
            $this->assertStringNotContainsString('ready with a reason', $faq->answer);
            $this->assertStringNotContainsString('Enter a clear reason when marking', $faq->answer);
        }
    }

    public function test_refresh_updates_only_shipped_answers_and_is_repeatable_without_overwriting_owner_edits(): void
    {
        $migration = require database_path('migrations/2026_11_18_000034_simplify_ready_workflow_guides.php');
        $previous = require database_path('migrations/2026_11_17_000032_refresh_current_feature_faqs.php');
        $question = 'What can staff do, and when should they ask the owner?';
        $old = collect($previous::ADDITIONS)->firstWhere('question', $question);
        $untouched = ChatbotFaq::where('question', $question)->sole();
        $untouched->update(['answer' => str_replace("\r\n", "\n", $old['answer'])]);
        $custom = ChatbotFaq::where('question', 'How do staff pack an order and mark it ready?')->sole();
        $custom->update(['answer' => 'Owner-written packing instructions retained for review.', 'audience' => 'owner',
            'active' => false, 'keywords' => 'custom packing', 'sort_order' => 900]);
        $beforeCustom = $custom->fresh()->getAttributes();
        $beforeOthers = ChatbotFaq::whereNotIn('id', [$custom->id, $untouched->id])->orderBy('id')->get()->toArray();
        $count = ChatbotFaq::count();

        $migration->up();
        $updated = $untouched->fresh()->getAttributes();
        $this->assertStringContainsString('signed-in name recorded automatically', $updated['answer']);
        $migration->up();
        $migration->down();

        $this->assertSame($count, ChatbotFaq::count());
        $this->assertSame($beforeCustom, $custom->fresh()->getAttributes());
        $this->assertSame($updated, $untouched->fresh()->getAttributes());
        $this->assertSame($beforeOthers, ChatbotFaq::whereNotIn('id', [$custom->id, $untouched->id])->orderBy('id')->get()->toArray());
    }
}
