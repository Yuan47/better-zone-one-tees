<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimpleWorkspaceGuidesTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_11_19_000035_refresh_simple_dashboard_catalog_and_receipt_guides.php');
    }

    public function test_new_installation_refreshes_exact_defaults_and_retires_only_obsolete_error_guides(): void
    {
        $migration = $this->migration();
        foreach ($migration::REFRESH as $change) {
            $faq = ChatbotFaq::where('question', $change['question'])->sole();
            $this->assertSame($change['answer'], $faq->answer, $change['question']);
            $this->assertTrue($faq->active);
        }
        foreach ($migration::DEACTIVATE as $change) {
            $faq = ChatbotFaq::where('question', $change['question'])->sole();
            $this->assertFalse($faq->active, $change['question']);
            $this->assertSame($change['previous_sha256'], hash('sha256', str_replace("\r\n", "\n", $faq->answer)));
            $this->assertNotContains($faq->id, app(StoreKnowledge::class)->approvedFaqs(['staff', 'owner'])->modelKeys());
        }
        foreach ($migration::ADDITIONS as $row) {
            $faq = ChatbotFaq::where('question', $row['question'])->sole();
            $this->assertSame($row['answer'], $faq->answer);
            $this->assertSame($row['audience'], $faq->audience);
            $this->assertTrue($faq->active);
        }
    }

    public function test_refresh_keeps_owner_answers_and_metadata_and_accepts_normalized_old_line_endings(): void
    {
        $migration = $this->migration();
        $previous = require database_path('migrations/2026_11_17_000032_refresh_current_feature_faqs.php');
        $question = 'Why do dashboard totals and charts show different numbers?';
        $original = collect($previous::ADDITIONS)->firstWhere('question', $question);
        $change = collect($migration::REFRESH)->firstWhere('question', $question);
        $faq = ChatbotFaq::where('question', $question)->sole();
        $faq->update(['answer' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $original['answer'])),
            'audience' => 'owner', 'active' => false, 'keywords' => 'Owner chosen scope wording', 'sort_order' => 999]);
        $metadata = $faq->fresh()->getAttributes();
        $migration->up();
        $updated = $faq->fresh()->getAttributes();
        $this->assertSame($change['answer'], $updated['answer']);
        unset($metadata['answer'], $metadata['updated_at'], $updated['answer'], $updated['updated_at']);
        $this->assertSame($metadata, $updated);

        foreach ($migration::REFRESH as $index => $entry) {
            ChatbotFaq::where('question', $entry['question'])->update([
                'answer' => 'Owner-approved custom instructions '.$index.'.', 'audience' => 'owner',
                'active' => false, 'keywords' => 'Custom owner keywords', 'sort_order' => 2000 + $index,
            ]);
        }
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());

        $faq->update(['answer' => $original['answer'].' ']);
        $nearMatch = $faq->fresh()->getAttributes();
        $migration->up();
        $this->assertSame($nearMatch, $faq->fresh()->getAttributes(), 'Even one additional character is an owner edit.');
    }

    public function test_error_retirement_preserves_owner_answer_audience_keywords_and_sort_order_edits(): void
    {
        $migration = $this->migration();
        $faq = ChatbotFaq::where('question', $migration::DEACTIVATE[0]['question'])->sole();
        $seeded = $faq->getAttributes();
        foreach ([['answer' => $faq->answer.' '], ['audience' => 'owner'], ['keywords' => 'Owner custom keyword'], ['sort_order' => 999]] as $edit) {
            $faq->forceFill($seeded)->save();
            $faq->update($edit + ['active' => true]);
            $before = $faq->fresh()->getAttributes();
            $migration->up();
            $this->assertSame($before, $faq->fresh()->getAttributes(), 'Retirement must preserve edited FAQ configuration.');
        }
        $faq->forceFill($seeded)->save();
        $faq->update(['active' => true]);
        $migration->up();
        $this->assertFalse($faq->fresh()->active, 'An unchanged default can be retired.');
        $retired = $faq->fresh()->getAttributes();
        $this->travel(1)->day();
        $migration->up();
        $migration->down();
        $this->assertSame($retired, $faq->fresh()->getAttributes());
        $this->travelBack();
    }

    public function test_missing_guides_are_added_once_without_replacing_matching_owner_entries(): void
    {
        $migration = $this->migration();
        $custom = ChatbotFaq::where('question', $migration::ADDITIONS[0]['question'])->sole();
        $custom->update(['answer' => 'Custom history instructions.', 'active' => false, 'audience' => 'owner', 'sort_order' => 4]);
        $before = $custom->fresh()->getAttributes();
        foreach (array_slice($migration::ADDITIONS, 1) as $row) {
            ChatbotFaq::where('question', $row['question'])->delete();
        }
        $migration->up();
        $snapshot = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $this->assertSame($snapshot, ChatbotFaq::orderBy('id')->get()->toArray());
        $this->assertSame($before, $custom->fresh()->getAttributes());
        foreach ($migration::ADDITIONS as $row) {
            $this->assertSame(1, ChatbotFaq::where('question', $row['question'])->count());
        }
    }

    public function test_updated_help_respects_customer_staff_and_owner_boundaries_without_contacting_ai(): void
    {
        config(['shop.groq.key' => '']);
        Http::preventStrayRequests();
        foreach ([
            ['customer', 'customer', 'How do I confirm delivery and leave my review?'],
            ['customer', 'customer', 'How do I ask for a refund or return?'],
            ['staff', 'staff', 'Why do dashboard totals and charts show different numbers?'],
            ['staff', 'staff', 'Why does a forecast show Needs history?'],
            ['admin', 'staff', 'How do I filter All, Low inventory or Hidden products?'],
            ['admin', 'staff', 'How do I remove a product or variant photo?'],
        ] as [$role, $side, $question]) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $faq = ChatbotFaq::where('question', $question)->sole();
            $this->postJson('/chat', ['message' => $question, 'side' => $side])->assertOk()->assertJsonPath('reply', $faq->answer);
        }
        $customerIds = app(StoreKnowledge::class)->approvedFaqs()->modelKeys();
        $staffIds = app(StoreKnowledge::class)->approvedFaqs(['customer', 'staff'])->modelKeys();
        foreach ($this->migration()::ADDITIONS as $row) {
            $faq = ChatbotFaq::where('question', $row['question'])->sole();
            $this->assertNotContains($faq->id, $customerIds);
            if ($row['audience'] === 'owner') {
                $this->assertNotContains($faq->id, $staffIds);
            }
        }
        Http::assertNothingSent();
    }
}
