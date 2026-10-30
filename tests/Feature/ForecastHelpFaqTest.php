<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForecastHelpFaqTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTIONS = [
        'How do staff read the two forecast charts?',
        'How is the historical growth forecast calculated?',
        'What do intercept, slope, and x mean in the sales forecast?',
        'What do Average miss and Relative error mean in historical error?',
        'Why does regression historical error need at least five completed months?',
        'Which sales months are included in the forecast history?',
        'What do Historical units and Available mean in the product demand table?',
        'How should staff use Suggested reorder?',
        'How do refunds and delivery fees affect forecast sales?',
        'How do staff sort the forecast tables?',
        'Can simulated sales prove that our forecasts are accurate?',
    ];

    public static function workspaceQuestions(): iterable
    {
        foreach (self::QUESTIONS as $question) {
            foreach (['staff', 'admin'] as $role) {
                foreach ([false, true] as $configured) {
                    yield $question.' '.$role.($configured ? ' AI configured' : ' basic') => [$question, $role, $configured];
                }
            }
        }
    }

    #[DataProvider('workspaceQuestions')]
    public function test_staff_and_owner_receive_the_approved_forecast_answer_without_ai(string $question, string $role, bool $configured): void
    {
        config(['shop.groq.key' => $configured ? 'synthetic-help-key' : null, 'shop.groq.model' => 'synthetic-help-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $faq = ChatbotFaq::where('question', $question)->firstOrFail();
        $this->assertSame('staff', $faq->audience);
        $this->assertTrue($faq->active);
        $this->assertMatchesRegularExpression('/^1\. .+\n2\. .+\n3\. .+\n4\. .+$/u', $faq->answer);

        $this->postJson(route('chat'), ['message' => $question, 'side' => 'staff'])
            ->assertOk()->assertJsonPath('mode', $configured ? 'store' : 'basic')->assertJsonPath('reply', $faq->answer);

        Http::assertNothingSent();
    }

    public function test_common_forecast_help_phrases_match_specific_approved_answers(): void
    {
        config(['shop.groq.key' => 'synthetic-help-key', 'shop.groq.model' => 'synthetic-help-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        foreach ([
            'Explain the average miss and relative error.' => self::QUESTIONS[3],
            'Why do we need five completed months?' => self::QUESTIONS[4],
            'Explain historical units meaning.' => self::QUESTIONS[6],
            'Explain suggested reorder limitations.' => self::QUESTIONS[7],
            'Can you explain simulated sales accuracy?' => self::QUESTIONS[10],
            'How do I sort forecast table rows highest to lowest?' => self::QUESTIONS[9],
        ] as $message => $question) {
            $answer = ChatbotFaq::where('question', $question)->firstOrFail()->answer;
            $this->postJson(route('chat'), ['message' => $message, 'side' => 'staff'])
                ->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', $answer);
        }
        Http::assertNothingSent();
    }

    public function test_forecast_guides_never_enter_customer_context_even_when_owner_uses_customer_mode(): void
    {
        config(['shop.groq.key' => 'synthetic-help-key', 'shop.groq.model' => 'synthetic-help-model']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Public shopping help.']]],
        ])]);
        $guides = ChatbotFaq::whereIn('question', self::QUESTIONS)->get();
        $this->assertCount(count(self::QUESTIONS), $guides);
        $publicIds = app(StoreKnowledge::class)->approvedFaqs()->pluck('id');
        foreach ($guides as $guide) {
            $this->assertNotContains($guide->id, $publicIds);
        }
        foreach (['customer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson(route('chat'), ['message' => 'Hello there, please introduce yourself.', 'side' => 'customer', 'audience' => 'staff'])
                ->assertOk()->assertJsonPath('mode', 'groq');
        }
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('forecast', $context);
            foreach ($guides as $guide) {
                $this->assertStringNotContainsString($guide->question, $system);
                $this->assertStringNotContainsString($guide->answer, $system);
            }
        }
    }

    public function test_repeated_migration_and_rollback_preserve_owner_edits_inactivity_and_audiences(): void
    {
        $faq = ChatbotFaq::where('question', self::QUESTIONS[3])->firstOrFail();
        $faq->update(['answer' => 'Owner-approved revised error explanation.', 'keywords' => 'custom forecast wording',
            'audience' => 'owner', 'active' => false, 'sort_order' => 42]);
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_29_000021_add_workspace_forecast_help.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }
}
