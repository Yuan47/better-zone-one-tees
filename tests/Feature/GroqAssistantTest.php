<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroqAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.groq.key' => 'fixture-groq-key', 'shop.groq.model' => 'openai/gpt-oss-20b']);
        Http::preventStrayRequests();
        ChatbotFaq::query()->delete();
    }

    public function test_general_help_uses_the_groq_endpoint_and_configured_model_with_a_bounded_request(): void
    {
        $options = [];
        Http::fake([self::ENDPOINT => function ($request, $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['choices' => [['message' => ['content' => '  Choose a style you like.  '], 'finish_reason' => 'stop']]]);
        }]);

        $this->chat()->assertOk()->assertJsonPath('mode', 'groq')->assertJsonPath('reply', 'Choose a style you like.')
            ->assertDontSee('fixture-groq-key');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT
            && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer fixture-groq-key')
            && $request['model'] === 'openai/gpt-oss-20b' && $request['max_completion_tokens'] === 1024
            && ! array_key_exists('max_tokens', $request->data())
            && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], 'customer assistant')
            && $request['messages'][1] === ['role' => 'user', 'content' => 'Hello there, please introduce yourself.']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(30, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    public static function missingSettings(): iterable
    {
        yield 'missing key' => [''];
        yield 'missing model' => [null, ''];
    }

    #[DataProvider('missingSettings')]
    public function test_missing_groq_settings_keep_basic_help_available_without_using_legacy_credentials(?string $key, ?string $model = 'openai/gpt-oss-20b'): void
    {
        config(['shop.groq.key' => $key, 'shop.groq.model' => $model, 'shop.grok.key' => 'legacy-key', 'shop.grok.model' => 'grok-4']);
        $this->chat()->assertOk()->assertJsonPath('mode', 'basic')->assertDontSee('legacy-key');
        Http::assertNothingSent();
    }

    public function test_approved_answer_does_not_depend_on_groq_availability(): void
    {
        ChatbotFaq::create(['question' => 'Can I request embroidery?', 'answer' => 'Ask staff to review your design first.', 'active' => true]);
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'Provider unavailable']], 503)]);

        $this->chat('Can I request embroidery?')->assertOk()->assertJsonPath('mode', 'store')
            ->assertJsonPath('reply', 'Ask staff to review your design first.');
        Http::assertNothingSent();
    }

    public static function failedStatuses(): iterable
    {
        foreach ([401, 403, 404, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('failedStatuses')]
    public function test_provider_rejections_return_a_safe_basic_answer_without_retries_or_provider_details(int $status): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'PRIVATE_PROVIDER_ERROR fixture-groq-key']], $status)]);
        $this->assertUnavailable();
        Http::assertSentCount(1);
    }

    public function test_connection_failure_returns_a_safe_basic_answer(): void
    {
        Http::fake([self::ENDPOINT => Http::failedConnection('PRIVATE_PROVIDER_ERROR fixture-groq-key')]);
        $this->assertUnavailable();
    }

    public static function malformedReplies(): iterable
    {
        yield 'missing choices' => [[]];
        yield 'missing message' => [['choices' => [[]]]];
        yield 'null content' => [['choices' => [['message' => ['content' => null]]]]];
        yield 'array content' => [['choices' => [['message' => ['content' => ['not a plain answer']]]]]];
        yield 'empty content' => [['choices' => [['message' => ['content' => '']]]]];
        yield 'blank content' => [['choices' => [['message' => ['content' => " \n\t "]]]]];
        yield 'truncated content' => [['choices' => [['message' => ['content' => 'Incomplete instructions'], 'finish_reason' => 'length']]]];
    }

    #[DataProvider('malformedReplies')]
    public function test_incomplete_or_invalid_provider_answers_use_basic_help_instead_of_an_empty_or_truncated_reply(array $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body)]);
        $this->assertUnavailable();
        Http::assertSentCount(1);
    }

    public function test_non_json_and_redirect_responses_fall_back_without_exposing_response_contents(): void
    {
        Http::fakeSequence()->push('<html>PRIVATE_PROVIDER_ERROR</html>', 200)->push('', 302, ['Location' => 'https://other.example.test/']);
        $this->assertUnavailable();
        $this->assertUnavailable();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() !== self::ENDPOINT);
    }

    public function test_owner_settings_and_customer_widget_render_groq_without_disclosing_the_key(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.settings'))->assertOk()
            ->assertViewHas('groqReady', true)->assertSee('GroqCloud')->assertDontSee('fixture-groq-key');
        $this->get(route('store'))->assertOk()->assertSee('Powered by GroqCloud')->assertDontSee('fixture-groq-key');
        config(['shop.groq.model' => '']);
        $this->get(route('admin.settings'))->assertOk()->assertViewHas('groqReady', false);
        $this->get(route('store'))->assertOk()->assertSee('Basic help · GroqCloud not connected')->assertDontSee('Powered by GroqCloud');
        Http::assertNothingSent();
    }

    private function chat(string $message = 'Hello there, please introduce yourself.')
    {
        return $this->postJson(route('chat'), ['message' => $message, 'side' => 'customer']);
    }

    private function assertUnavailable(): void
    {
        $this->chat()->assertOk()->assertJsonPath('mode', 'unavailable')
            ->assertJsonPath('reply', fn ($reply) => str_starts_with($reply, 'The AI assistant is temporarily unavailable. Basic store answer: ')
                && str_contains($reply, 'approved store FAQs'))
            ->assertDontSee('PRIVATE_PROVIDER_ERROR')->assertDontSee('fixture-groq-key')->assertDontSee('Incomplete instructions');
    }
}
