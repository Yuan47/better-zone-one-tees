<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssistantGuideTest extends TestCase
{
    use RefreshDatabase;

    public static function guides(): iterable
    {
        $questions = [
            'customer' => [
                'How do I create a customer account?',
                'How do I sign in to my customer account?',
                'How do I enter my email verification code?',
                'How do I reset a forgotten password?',
                'How do I update my profile and delivery address?',
                'How do I save or remove a favorite product?',
                'How do I add items to my cart and check out?',
                'How do I open my order history and track an order?',
                'How do I find my invoice and payment references?',
                'How do I choose partial payment and pay the balance later?',
                'How do I confirm delivery and leave my review?',
                'How do I ask for a refund or return?',
            ],
            'staff' => [
                'How do staff create a walk-in sale?',
                'How do staff record cash received on an invoice?',
                'How do staff pack an order and mark it ready?',
                'How do staff book and check a courier delivery?',
                'How do staff help a customer confirm an online order was received?',
                'How do staff handle a pending or uncertain payment?',
                'How do staff read and export sales reports?',
                'How do staff understand the forecast formulas?',
                'What do forecast history and accuracy warnings mean?',
            ],
            'owner' => [
                'How does the owner add a product and its size and color variants?',
                'How does the owner update stock when another change conflicts?',
                'How does the owner activate a staff account with its setup code?',
                'How does the owner manage FAQ audiences and import answers?',
                'How does the owner review the activity log?',
                'How does the owner review store settings and service readiness?',
                'How does the owner inspect a return and process its refund?',
            ],
        ];
        foreach ($questions as $audience => $entries) {
            foreach ($entries as $question) {
                foreach ([false, true] as $configured) {
                    yield $audience.' '.$question.($configured ? ' configured AI' : ' basic help') => [$audience, $question, $configured];
                }
            }
        }
    }

    #[DataProvider('guides')]
    public function test_scoped_how_to_question_returns_its_approved_steps_without_calling_ai(string $audience, string $question, bool $configured): void
    {
        config(['shop.groq.key' => $configured ? 'test-groq' : null, 'shop.groq.model' => 'guide-test']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Unexpected generated answer.']]]])]);
        $role = ['customer' => 'customer', 'staff' => 'staff', 'owner' => 'admin'][$audience];
        $this->actingAs(User::factory()->create(['role' => $role]));
        $faq = ChatbotFaq::where('question', $question)->firstOrFail();
        $this->assertSame($audience, $faq->audience);

        $this->postJson(route('chat'), ['message' => $question, 'side' => $audience === 'customer' ? 'customer' : 'staff'])
            ->assertOk()->assertJsonPath('mode', $configured ? 'store' : 'basic')->assertJsonPath('reply', $faq->answer);

        Http::assertNothingSent();
    }

    public static function registrationQuestions(): iterable
    {
        foreach ([
            'How can I register?',
            'How to sign up?',
            'How do I create an account?',
            'Can I register for an account?',
            'Where can I sign up?',
            'Help me create a customer account.',
        ] as $question) {
            yield $question => [$question];
        }
    }

    #[DataProvider('registrationQuestions')]
    public function test_customer_registration_paraphrases_work_without_an_ai_connection(string $question): void
    {
        config(['shop.groq.key' => null]);
        Http::preventStrayRequests();
        Http::fake();
        $faq = ChatbotFaq::where('question', 'How do I create a customer account?')->firstOrFail();

        $this->postJson(route('chat'), ['message' => $question, 'side' => 'customer'])
            ->assertOk()->assertJsonPath('mode', 'basic')->assertJsonPath('reply', $faq->answer);

        Http::assertNothingSent();
    }
}
