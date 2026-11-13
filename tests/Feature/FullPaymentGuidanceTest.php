<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FullPaymentGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_installment_offers_are_not_published_but_their_history_is_kept(): void
    {
        $faq = ChatbotFaq::where('question', 'Can I pay an order in installments?')->firstOrFail();
        $this->assertFalse($faq->active);
        $this->assertStringContainsString('Partial payments are available', $faq->answer);
        $this->assertNotContains($faq->id, app(StoreKnowledge::class)->approvedFaqs()->pluck('id'));

        $faq->update(['active' => true, 'answer' => 'Owner wording for a retired feature.', 'keywords' => 'owner customized', 'sort_order' => 17]);
        $migration = require database_path('migrations/2026_11_12_000031_refresh_full_payment_and_workspace_guides.php');
        $migration->up();
        $faq->refresh();
        $this->assertFalse($faq->active);
        $this->assertSame('Owner wording for a retired feature.', $faq->answer);
        $this->assertSame('owner customized', $faq->keywords);
        $this->assertSame(17, $faq->sort_order);
        $snapshot = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $migration->down();
        $this->assertSame($snapshot, ChatbotFaq::orderBy('id')->get()->toArray());
    }

    public function test_new_guides_keep_owner_edits_and_inactive_switches_on_repeat_migration(): void
    {
        $faq = ChatbotFaq::where('question', 'How do I save an address and choose my delivery pin?')->firstOrFail();
        $faq->update(['answer' => 'Owner-approved address instructions.', 'active' => false, 'audience' => 'owner']);
        $migration = require database_path('migrations/2026_11_12_000031_refresh_full_payment_and_workspace_guides.php');
        $migration->up();
        $this->assertSame('Owner-approved address instructions.', $faq->fresh()->answer);
        $this->assertFalse($faq->fresh()->active);
        $this->assertSame('owner', $faq->fresh()->audience);
    }

    public function test_installment_policy_is_full_payment_even_if_an_old_faq_is_reactivated(): void
    {
        config(['shop.groq.key' => 'synthetic-key', 'shop.groq.model' => 'synthetic-model']);
        Http::preventStrayRequests();
        ChatbotFaq::where('question', 'Can I pay an order in installments?')->update(['active' => true, 'answer' => 'Old answer: pay a tiny deposit.']);
        foreach (['Can I pay an order in installments?', 'Can I choose a partial payment for ZOT-261112-TEST?', 'What is the minimum down payment?'] as $message) {
            $this->postJson(route('chat'), ['message' => $message, 'side' => 'customer'])
                ->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', StoreKnowledge::PAYMENT_HELP)
                ->assertDontSee('tiny deposit');
        }
        Http::assertNothingSent();
    }

    public function test_new_customer_guides_are_plain_public_help_without_internal_booking_steps(): void
    {
        config(['shop.groq.key' => null]);
        Http::preventStrayRequests();
        foreach ([
            'Which payment options can I use now?',
            'How do I save an address and choose my delivery pin?',
            'Will my cart stay saved after I sign out or close the browser?',
            'How do I read My Purchases and the To Ship status?',
            'Are the product pictures actual supplier photographs?',
        ] as $question) {
            $faq = ChatbotFaq::where('question', $question)->firstOrFail();
            $this->assertSame('customer', $faq->audience);
            $this->postJson(route('chat'), ['message' => $question, 'side' => 'customer'])
                ->assertOk()->assertJsonPath('mode', 'basic')->assertJsonPath('reply', $faq->answer);
        }
        $internal = ChatbotFaq::where('question', 'How do we demonstrate Lalamove booking without a real delivery?')->firstOrFail();
        $this->assertNotContains($internal->id, app(StoreKnowledge::class)->approvedFaqs()->pluck('id'));
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->postJson(route('chat'), ['message' => $internal->question, 'side' => 'staff'])
            ->assertOk()->assertJsonPath('reply', $internal->answer);
        Http::assertNothingSent();
    }

    public static function splitPaymentPhrases(): array
    {
        return [
            ['Can I pay half now and half next week?'],
            ['Can I pay 50% now?'],
            ['May I pay 50 percent first, then the rest later?'],
            ['Can I split payment over two weeks?'],
            ['Is split-payment checkout available?'],
            ['Can I pay in two payments?'],
        ];
    }

    #[DataProvider('splitPaymentPhrases')]
    public function test_split_payment_wording_cannot_revive_an_owner_edited_obsolete_faq(string $message): void
    {
        config(['shop.groq.key' => 'synthetic-key', 'shop.groq.model' => 'synthetic-model']);
        Http::preventStrayRequests();
        ChatbotFaq::create(['question' => $message, 'answer' => 'OBSOLETE OFFER: pay half now and the rest next week.',
            'audience' => 'customer', 'active' => true, 'keywords' => $message, 'sort_order' => 1]);
        $this->postJson(route('chat'), ['message' => $message, 'side' => 'customer'])->assertOk()
            ->assertJsonPath('reply', StoreKnowledge::PAYMENT_HELP)->assertDontSee('OBSOLETE OFFER');
        Http::assertNothingSent();
    }

    public static function quantityPhrases(): array
    {
        return [['How do I pay for 50 pieces at wholesale?'], ['Can I pay for half a dozen shirts?'], ['How do I pay for 50% cotton shirts?']];
    }

    #[DataProvider('quantityPhrases')]
    public function test_clothing_quantities_and_fabric_percentages_do_not_trigger_split_payment_guidance(string $message): void
    {
        config(['shop.groq.key' => null]);
        Http::preventStrayRequests();
        ChatbotFaq::create(['question' => $message, 'answer' => 'Choose the correct clothing quantity and fabric, then check the full cart total.',
            'audience' => 'customer', 'active' => true, 'keywords' => $message, 'sort_order' => 1]);
        $response = $this->postJson(route('chat'), ['message' => $message, 'side' => 'customer'])->assertOk();
        $this->assertNotSame(StoreKnowledge::PAYMENT_HELP, $response->json('reply'));
        Http::assertNothingSent();
    }
}
