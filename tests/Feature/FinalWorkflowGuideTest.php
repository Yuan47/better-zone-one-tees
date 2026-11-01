<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinalWorkflowGuideTest extends TestCase
{
    use RefreshDatabase;

    private const MOVE_QUESTION = 'Why did an old store link take me to the updated website?';

    private const FORECAST_QUESTION = 'Why can the two forecasts disagree after a low-sales month?';

    public static function approvedHelpCases(): iterable
    {
        foreach ([false, true] as $configured) {
            yield 'customer move '.($configured ? 'configured' : 'basic') => [self::MOVE_QUESTION, 'customer', 'customer', $configured];
            foreach (['staff', 'admin'] as $role) {
                yield $role.' forecast '.($configured ? 'configured' : 'basic') => [self::FORECAST_QUESTION, $role, 'staff', $configured];
            }
        }
    }

    #[DataProvider('approvedHelpCases')]
    public function test_missing_workflow_help_uses_the_approved_answer_without_an_ai_request(string $question, string $role, string $side, bool $configured): void
    {
        config(['shop.groq.key' => $configured ? 'synthetic-guide-key' : null, 'shop.groq.model' => 'synthetic-guide-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $faq = ChatbotFaq::where('question', $question)->sole();
        $this->assertSame($side, $faq->audience);
        $this->assertTrue($faq->active);

        $this->postJson(route('chat'), ['message' => $question, 'side' => $side])
            ->assertOk()->assertJsonPath('mode', $configured ? 'store' : 'basic')->assertJsonPath('reply', $faq->answer);

        Http::assertNothingSent();
    }

    public function test_common_sparse_history_and_old_link_questions_match_the_specific_guides(): void
    {
        config(['shop.groq.key' => null]);
        Http::preventStrayRequests();
        $move = ChatbotFaq::where('question', self::MOVE_QUESTION)->sole();
        $this->postJson(route('chat'), ['message' => 'I opened an old website link.', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', $move->answer);

        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $forecast = ChatbotFaq::where('question', self::FORECAST_QUESTION)->sole();
        $this->postJson(route('chat'), ['message' => 'Why do the forecasts disagree with sparse forecast history?', 'side' => 'staff'])
            ->assertOk()->assertJsonPath('reply', $forecast->answer);
        Http::assertNothingSent();
    }

    public function test_forecast_interpretation_never_enters_customer_context_even_for_an_owner_in_customer_mode(): void
    {
        config(['shop.groq.key' => 'synthetic-guide-key', 'shop.groq.model' => 'synthetic-guide-model']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Public shopping help.']]],
        ])]);
        $faq = ChatbotFaq::where('question', self::FORECAST_QUESTION)->sole();
        $this->assertNotContains($faq->id, app(StoreKnowledge::class)->approvedFaqs()->pluck('id'));

        foreach (['customer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson(route('chat'), ['message' => 'Hello, please introduce yourself.', 'side' => 'customer', 'audience' => 'staff'])
                ->assertOk()->assertJsonPath('reply', 'Public shopping help.');
        }

        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('forecast', $context);
            $this->assertStringNotContainsString($faq->question, $system);
            $this->assertStringNotContainsString($faq->answer, $system);
        }
    }

    public function test_repeated_migration_and_rollback_preserve_edits_audiences_inactivity_and_existing_receipt_help(): void
    {
        foreach ([self::MOVE_QUESTION, self::FORECAST_QUESTION] as $question) {
            ChatbotFaq::where('question', $question)->sole()->update([
                'answer' => 'Owner-approved customized guidance.', 'keywords' => 'custom guide wording',
                'audience' => 'owner', 'active' => false, 'sort_order' => 17,
            ]);
        }
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_11_01_000024_add_website_move_and_forecast_interpretation_guides.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
        $this->assertSame(1, ChatbotFaq::where('question', 'What should I do if my payment receipt email is missing?')->count());
    }
}
