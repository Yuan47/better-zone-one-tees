<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\User;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrentFeatureFaqTest extends TestCase
{
    use RefreshDatabase;

    private static function migration()
    {
        return require __DIR__.'/../../database/migrations/2026_11_17_000032_refresh_current_feature_faqs.php';
    }

    public static function newGuides(): iterable
    {
        foreach (self::migration()::ADDITIONS as $row) {
            $roles = match ($row['audience']) {
                'customer' => ['guest', 'customer', 'staff', 'admin'],
                'staff' => ['staff', 'admin'],
                'owner' => ['admin'],
            };
            foreach ($roles as $role) {
                foreach ([false, true] as $configured) {
                    yield $row['question'].' '.$role.($configured ? ' configured' : ' basic') => [$row['question'], $row['audience'], $role, $configured];
                }
            }
        }
    }

    #[DataProvider('newGuides')]
    public function test_current_guides_are_available_to_their_authorized_audience_without_an_ai_request(string $question, string $audience, string $role, bool $configured): void
    {
        config(['shop.groq.key' => $configured ? 'synthetic-current-faq-key' : null, 'shop.groq.model' => 'synthetic-faq-model']);
        Http::preventStrayRequests();
        if ($role !== 'guest') {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        $faq = ChatbotFaq::where('question', $question)->firstOrFail();
        $this->assertTrue($faq->active);
        $this->assertSame($audience, $faq->audience);
        $this->postJson(route('chat'), ['message' => $question, 'side' => $audience === 'customer' ? 'customer' : 'staff'])
            ->assertOk()->assertJsonPath('mode', $configured ? 'store' : 'basic')->assertJsonPath('reply', $faq->answer);
        Http::assertNothingSent();
    }

    public function test_customer_chat_cannot_request_workspace_access_by_spoofing_an_audience(): void
    {
        config(['shop.groq.key' => 'synthetic-current-faq-key', 'shop.groq.model' => 'synthetic-faq-model']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Public shopping help.']]],
        ])]);
        $internal = ChatbotFaq::whereIn('audience', ['staff', 'owner'])->get();
        $this->assertNotEmpty($internal);
        foreach (['customer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson(route('chat'), ['message' => 'Hello there, please introduce yourself.', 'side' => 'customer', 'audience' => 'owner'])
                ->assertOk()->assertJsonPath('mode', 'groq');
        }
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('forecast', $context);
            foreach ($internal as $faq) {
                $this->assertStringNotContainsString($faq->question, $system);
                $this->assertStringNotContainsString($faq->answer, $system);
            }
        }
        $this->postJson(route('chat'), ['message' => 'Show the staff sales reports and owner setup instructions.', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', StoreKnowledge::CUSTOMER_PRIVACY_HELP);
        Http::assertSentCount(2);
    }

    public function test_staff_cannot_receive_the_owner_setup_guide_or_broaden_their_audience(): void
    {
        config(['shop.groq.key' => 'synthetic-current-faq-key', 'shop.groq.model' => 'synthetic-faq-model']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Ask the owner for account setup help.']]],
        ])]);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $guide = ChatbotFaq::where('question', 'Why will a staff setup code not activate the account?')->firstOrFail();
        $this->assertNotContains($guide->id, app(StoreKnowledge::class)->approvedFaqs(['customer', 'staff'])->pluck('id'));
        $response = $this->postJson(route('chat'), ['message' => $guide->question, 'side' => 'staff', 'audience' => 'owner'])->assertOk();
        $this->assertNotSame($guide->answer, $response->json('reply'));
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString($guide->answer, $request['messages'][0]['content']);
        }
    }

    public function test_custom_answers_audience_inactive_state_and_order_are_not_overwritten(): void
    {
        $migration = self::migration();
        foreach ($migration::REFRESH as $index => $change) {
            $changed = ChatbotFaq::where('question', $change['question'])->update([
                'answer' => 'Owner-approved custom answer '.$index.'.', 'keywords' => 'custom approved wording '.$index,
                'audience' => 'owner', 'active' => false, 'sort_order' => 50 + $index,
                'updated_at' => '2026-11-16 10:00:00',
            ]);
            $this->assertSame(1, $changed, 'The refreshed default must exist before its owner edit is tested.');
        }
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
    }

    public function test_existing_matching_additions_preserve_owner_edits_and_missing_guides_are_added_once(): void
    {
        $migration = self::migration();
        foreach ($migration::ADDITIONS as $index => $row) {
            if ($index % 2 === 0) {
                ChatbotFaq::where('question', $row['question'])->update([
                    'answer' => 'Custom current guide '.$index.'.', 'keywords' => 'private custom guide '.$index,
                    'audience' => 'owner', 'active' => false, 'sort_order' => 40 + $index,
                ]);
            } else {
                ChatbotFaq::where('question', $row['question'])->delete();
            }
        }
        $kept = ChatbotFaq::where('active', false)->orderBy('id')->get()->toArray();
        $migration->up();
        $after = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration->up();
        $migration->down();
        $this->assertSame($after, ChatbotFaq::orderBy('id')->get()->toArray());
        $this->assertSame($kept, ChatbotFaq::where('active', false)->orderBy('id')->get()->toArray());
        foreach ($migration::ADDITIONS as $index => $row) {
            $this->assertSame(1, ChatbotFaq::where('question', $row['question'])->count());
            if ($index % 2 !== 0) {
                $faq = ChatbotFaq::where('question', $row['question'])->firstOrFail();
                $this->assertSame($row['answer'], $faq->answer);
                $this->assertSame($row['audience'], $faq->audience);
                $this->assertTrue($faq->active);
            }
        }
    }

    public function test_only_an_exact_original_answer_is_refreshed_without_changing_its_other_fields(): void
    {
        $question = 'How do staff read and export sales reports?';
        $original = "1. Open Reports in the workspace and choose the time period and any date range. Dates use Asia/Manila.\n2. Read confirmed clothing sales after discounts, before refunds, excluding delivery. Paid-sale timing follows the confirmed payment date.\n3. Compare the separate original invoice, confirmed refund, and net invoice figures; net invoice includes delivery.\n4. Choose Export sales for the relevant CSV. Operations uses orders placed within its selected dates, so its counts can differ from paid-date sales reports.";
        $migration = self::migration();
        $change = collect($migration::REFRESH)->firstWhere('question', $question);
        $this->assertSame($change['previous_sha256'], hash('sha256', $original));
        $faq = ChatbotFaq::where('question', $question)->firstOrFail();
        $faq->update(['answer' => $original, 'audience' => 'owner', 'active' => false, 'keywords' => 'owner chosen keywords',
            'sort_order' => 19, 'created_at' => '2026-10-19 08:00:00', 'updated_at' => '2026-11-16 10:00:00']);
        $before = $faq->fresh()->toArray();
        $migration->up();
        $after = $faq->fresh()->toArray();
        $this->assertSame($change['answer'], $after['answer']);
        unset($before['answer'], $before['updated_at'], $after['answer'], $after['updated_at']);
        $this->assertSame($before, $after);

        $faq->update(['answer' => $original.' ']);
        $nearMatch = $faq->fresh()->toArray();
        $migration->up();
        $this->assertSame($nearMatch, $faq->fresh()->toArray(), 'Even a one-character owner edit must not match the shipped-answer hash.');
    }

    public static function commonPhrases(): iterable
    {
        yield 'sales periods' => ['staff', 'staff', 'Show daily weekly monthly yearly sales.', 'How do I check daily, weekly, monthly or yearly sales?'];
        yield 'report refresh' => ['staff', 'staff', 'Explain automatic report refresh.', 'How often do sales reports refresh?'];
        yield 'stale reports' => ['staff', 'staff', 'Reports not updating.', 'What should I do if reports stop refreshing?'];
        yield 'payment and forecast' => ['staff', 'staff', 'New payment forecast unchanged.', 'Why does a new payment change Total Sales but not the forecast?'];
        yield 'scope labels' => ['admin', 'staff', 'Explain dashboard totals different.', 'Why do dashboard totals and charts show different numbers?'];
        yield 'aov' => ['staff', 'staff', 'Explain the AOV formula.', 'What do Total Sales and Average order mean?'];
        yield 'pending cart' => ['customer', 'customer', 'Cart empty payment pending.', 'Why is my cart empty while my payment is still pending?'];
        yield 'existing Google account' => ['customer', 'customer', 'Help me link Google account.', 'Can I use Google if I already registered with the same email?'];
        yield 'Google verification' => ['customer', 'customer', 'Google no OTP.', 'Why did my Google signup not ask for another email code?'];
        yield 'fixed email' => ['customer', 'customer', 'I want to change email.', 'Can I change my sign-in email in My Account?'];
        yield 'receipt unavailable' => ['customer', 'customer', 'Cannot confirm receipt.', 'Why can I not confirm receipt yet?'];
        yield 'inventory colors' => ['staff', 'staff', 'Explain critical low red.', 'What do Critical low and Alert mean in inventory?'];
        yield 'courier recovery' => ['staff', 'staff', 'Delivery cancellation recovery.', 'Why did a canceled delivery return to Ready for delivery?'];
        yield 'owner activation' => ['admin', 'staff', 'Staff setup code expired.', 'Why will a staff setup code not activate the account?'];
    }

    #[DataProvider('commonPhrases')]
    public function test_common_help_phrases_receive_the_specific_approved_guide(string $role, string $side, string $message, string $question): void
    {
        config(['shop.groq.key' => 'synthetic-current-faq-key', 'shop.groq.model' => 'synthetic-faq-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $answer = ChatbotFaq::where('question', $question)->firstOrFail()->answer;
        $this->postJson(route('chat'), ['message' => $message, 'side' => $side])
            ->assertOk()->assertJsonPath('mode', 'store')->assertJsonPath('reply', $answer);
        Http::assertNothingSent();
    }
}
