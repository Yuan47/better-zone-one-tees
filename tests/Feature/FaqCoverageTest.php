<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FaqCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function chat(string $message, string $side = 'customer')
    {
        return $this->postJson(route('chat'), ['message' => $message, 'side' => $side]);
    }

    private function invoice(User $customer)
    {
        $product = Product::create(['name' => 'Invoice FAQ Tee', 'slug' => 'invoice-faq-tee', 'category' => '', 'description' => 'Test']);
        $variant = $product->variants()->create(['sku' => 'INVOICE-FAQ', 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 20000, 'wholesale_price' => 19000, 'reseller_price' => 18000]);
        $order = app(OrderFlow::class)->reserve([$variant->id => 10], ['recipient' => 'FAQ Buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'payment_plan' => 'partial'], $customer->id);
        $order->update(['payment_status' => 'partially_paid', 'amount_paid' => 50000]);

        return $order->fresh();
    }

    public function test_new_faq_migration_is_additive_and_preserves_owner_edits_and_inactive_answers(): void
    {
        $migration = require database_path('migrations/2026_10_17_000001_add_operational_chatbot_faqs.php');
        $edited = ChatbotFaq::where('question', 'Can I pay an order in installments?')->firstOrFail();
        $edited->update(['answer' => 'Owner-edited installment answer.', 'keywords' => 'owner rules', 'sort_order' => 456]);
        $inactive = ChatbotFaq::where('question', 'Will I receive payment reminders?')->firstOrFail();
        $inactive->update(['active' => false]);
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $migration->up();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }

    public function test_operational_faqs_cover_implemented_rules_without_depending_on_ai_or_email_configuration(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        $faqs = ChatbotFaq::whereBetween('sort_order', [100, 299])->orderBy('sort_order')->get();
        $this->assertCount(18, $faqs);
        foreach ($faqs->where('audience', 'customer')->take(12) as $faq) {
            $this->chat($faq->question)->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', $faq->answer);
        }
        Http::assertNothingSent();
    }

    public function test_receipts_order_history_and_favorites_have_approved_faq_guidance(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        foreach (['Where can I find my payment receipt and order reference?', 'Where are my order history and favorites?', 'Why was I signed out after being inactive?'] as $question) {
            $faq = ChatbotFaq::where('question', $question)->firstOrFail();
            $this->chat($question)->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', $faq->answer);
        }
        Http::assertNothingSent();
    }

    public function test_operational_faqs_answer_common_paraphrases_without_ai(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        foreach ([
            'What is the minimum partial payment eligibility?' => 'more than ₱1,000',
            'Can I open another installment?' => 'only one open',
            'Will you email payment reminders?' => '3 days and 1 day',
            'I need to reset password' => '60 minutes',
            'How can I confirm receipt?' => 'rating and review',
        ] as $question => $answer) {
            $response = $this->chat($question)->assertOk()->assertJsonPath('mode', 'store');
            $this->assertStringContainsString($answer, $response->json('reply'), $question);
        }
        Http::assertNothingSent();
    }

    public function test_closed_invoice_is_not_presented_as_an_outstanding_payment(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        $customer = User::factory()->create();
        $order = $this->invoice($customer);
        $order->update(['payment_status' => 'pending', 'amount_paid' => 0]);
        app(OrderFlow::class)->cancel($order);
        $this->actingAs($customer);
        $this->chat('Show my remaining balance '.$order->number)->assertOk()->assertJsonPath('mode', 'store')->assertSee('No payment is due on this closed invoice');
        Http::assertNothingSent();
    }

    public function test_policy_question_with_order_reference_uses_the_approved_faq(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        ChatbotFaq::create(['question' => 'Can I return my paid order?', 'answer' => 'Approved return guidance: request a refund after delivery.',
            'keywords' => 'return, refunds', 'active' => true]);
        foreach (['Can I return my paid order? ZOT-261017-ABCD1234', 'I want to return my paid order ZOT-261017-ABCD1234'] as $message) {
            $this->chat($message)->assertOk()->assertJsonPath('mode', 'store')
                ->assertJsonPath('reply', 'Approved return guidance: request a refund after delivery.');
        }
        Http::assertNothingSent();
    }

    public function test_installment_and_reminder_policy_questions_can_include_an_order_reference(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        foreach ([
            'Can I pay an order in installments? ZOT-261017-ABCD1234' => 'more than ₱1,000',
            'Will you send payment reminders for ZOT-261017-ABCD1234?' => '3 days and 1 day',
            'Can I open another installment for ZOT-261017-ABCD1234?' => 'only one open',
            'How do I pay the remaining invoice balance for ZOT-261017-ABCD1234?' => 'available payment action',
            'How is the outstanding balance calculated for ZOT-261017-ABCD1234?' => 'original invoice total minus payments received',
            'What is the deadline for a partial-payment invoice ZOT-261017-ABCD1234?' => 'between 7 and 30 days',
        ] as $message => $answer) {
            $this->chat($message)->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', fn ($reply) => str_contains($reply, $answer));
        }
        Http::assertNothingSent();
    }

    public function test_balance_and_status_requests_remain_private_and_take_precedence_over_faqs(): void
    {
        config(['shop.groq.key' => 'test-groq']);
        Http::fake();
        $customer = User::factory()->create();
        $order = $this->invoice($customer);
        ChatbotFaq::create(['question' => 'Show my remaining balance '.$order->number, 'answer' => 'Wrong balance: zero.', 'active' => true]);
        $this->chat('Show my remaining balance '.$order->number)->assertJsonPath('mode', 'store')->assertDontSee('Wrong balance')->assertDontSee('1,400.00');
        $this->actingAs(User::factory()->create());
        $this->chat('Show my remaining balance '.$order->number)->assertDontSee('1,400.00')->assertDontSee('500.00');
        $this->actingAs($customer);
        $this->chat('Show my remaining balance '.$order->number)->assertJsonPath('mode', 'store')->assertSee('1,900.00')->assertSee('500.00')->assertSee('1,400.00')->assertDontSee('Wrong balance');
        $this->chat('Track my order '.$order->number)->assertJsonPath('mode', 'store')->assertSee('partially_paid')->assertDontSee('Wrong balance');
        $this->chat('What is my remaining balance?')->assertSee('full order number')->assertDontSee('1,400.00');
        Http::assertNothingSent();
    }
}
