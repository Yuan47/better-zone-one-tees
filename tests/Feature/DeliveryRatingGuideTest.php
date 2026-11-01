<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class DeliveryRatingGuideTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTIONS = [
        'How do I confirm receipt of an online order?',
        'When can I request a refund?',
        'How do I confirm delivery and leave my review?',
        'How do I ask for a refund or return?',
        'How do staff help a customer confirm an online order was received?',
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_01_000022_refresh_default_delivery_rating_guides.php');
    }

    private function restorePreviousDefaults(): void
    {
        ChatbotFaq::whereIn('question', self::QUESTIONS)->delete();
        (require database_path('migrations/2026_10_17_000001_add_operational_chatbot_faqs.php'))->up();
        (require database_path('migrations/2026_10_19_000016_add_faq_audiences_and_how_to_guides.php'))->up();
    }

    public function test_default_guides_explain_required_rating_and_optional_written_review(): void
    {
        foreach (self::QUESTIONS as $question) {
            $answer = ChatbotFaq::where('question', $question)->sole()->answer;
            $this->assertStringContainsString('The written review is optional.', $answer, $question);
            $this->assertStringContainsString('rating', $answer, $question);
            $this->assertStringNotContainsString('required review', $answer, $question);
            $this->assertStringNotContainsString('Confirm Delivered & submit review', $answer, $question);
        }
        $this->assertSame('staff', ChatbotFaq::where('question', self::QUESTIONS[4])->sole()->audience);
    }

    public function test_refresh_updates_only_unchanged_answers_and_preserves_other_fields(): void
    {
        $this->restorePreviousDefaults();
        foreach (self::QUESTIONS as $question) {
            ChatbotFaq::where('question', $question)->update([
                'active' => false, 'audience' => 'owner', 'keywords' => 'owner matching words',
                'sort_order' => 998, 'updated_at' => '2026-10-30 12:00:00',
            ]);
        }
        $before = ChatbotFaq::orderBy('id')->get()->keyBy('id');
        $this->migration()->up();
        foreach (ChatbotFaq::orderBy('id')->get() as $faq) {
            $prior = $before->get($faq->id);
            if (in_array($faq->question, self::QUESTIONS, true)) {
                $this->assertStringContainsString('The written review is optional.', $faq->answer);
                $this->assertSame(Arr::except($prior->toArray(), ['answer']), Arr::except($faq->toArray(), ['answer']));
            } else {
                $this->assertSame($prior->toArray(), $faq->toArray());
            }
        }
    }

    public function test_refresh_preserves_owner_edited_answers_including_case_changes(): void
    {
        $this->restorePreviousDefaults();
        foreach (self::QUESTIONS as $question) {
            $faq = ChatbotFaq::where('question', $question)->sole();
            $faq->update(['answer' => strtoupper($faq->answer), 'active' => false, 'audience' => 'owner']);
        }
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $this->migration()->up();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }

    public function test_refresh_does_not_recreate_missing_answers_and_repeated_runs_are_safe(): void
    {
        ChatbotFaq::where('question', self::QUESTIONS[0])->delete();
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }
}
