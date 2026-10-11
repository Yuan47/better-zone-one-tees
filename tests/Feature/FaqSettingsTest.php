<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFlow;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FaqSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function faqInput(array $data = []): array
    {
        return $data + ['question' => 'Can I request embroidery?', 'answer' => 'Ask store staff about an embroidery quotation.',
            'keywords' => 'embroidery, stitching', 'active' => 1, 'sort_order' => 5];
    }

    private function csv(array $rows, array $headers = ['question', 'answer', 'keywords', 'active', 'sort_order']): UploadedFile
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('approved-faqs.csv', $content)->mimeType('text/csv');
    }

    private function chat(string $message, string $side = 'customer')
    {
        return $this->postJson(route('chat'), ['message' => $message, 'side' => $side]);
    }

    private function order(User $owner)
    {
        $product = Product::create(['name' => 'Sample Tee', 'slug' => 'faq-tee-'.uniqid(), 'category' => '', 'description' => 'Sample']);
        $variant = $product->variants()->create(['sku' => 'FAQ-'.uniqid(), 'size' => 'M', 'color' => 'White', 'color_group' => 'WHITE', 'stock' => 10,
            'retail_price' => 10000, 'wholesale_price' => 9500, 'reseller_price' => 9000]);

        return app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Sample Buyer', 'phone' => '09123456789',
            'address' => 'Pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $owner->id);
    }

    public function test_migration_keeps_legacy_guidance_and_seeds_verified_facts_only_once(): void
    {
        DB::table('shop_settings')->updateOrInsert(['key' => 'chatbot_faq'], ['value' => 'Test 1, Test 2, Test 3.']);
        $migration = require database_path('migrations/2026_10_11_000004_create_chatbot_faqs_and_store_info.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('chatbot_faqs', 4);
        $this->assertSame('Test 1, Test 2, Test 3.', app(StoreKnowledge::class)->info()['store_note']);
        $this->assertSame('Test 1, Test 2, Test 3.', DB::table('shop_settings')->where('key', 'chatbot_faq')->value('value'));
        $this->assertSame('', app(StoreKnowledge::class)->info()['opening_hours']);
        $this->assertSame('', app(StoreKnowledge::class)->info()['support_email']);
        $this->assertTrue(ChatbotFaq::where('answer', 'like', '%5% off the Re-seller%')->exists());
        $this->assertTrue(ChatbotFaq::where('answer', 'like', '%verified PayMongo%')->exists());
        app(StoreKnowledge::class)->saveInfo(['store_note' => 'New owner note']);
        $migration->up();
        $this->assertSame('New owner note', app(StoreKnowledge::class)->info()['store_note']);
    }

    public function test_admin_creates_edits_deactivates_and_deletes_faq_with_immediate_chat_updates(): void
    {
        config(['shop.grok.key' => null]);
        $this->login();
        $this->get(route('admin.faqs.create'))->assertOk()->assertSee('Add an approved FAQ');
        $this->post(route('admin.faqs.store'), $this->faqInput(['sort_order' => '']))->assertRedirect(route('admin.settings'))->assertSessionHasNoErrors();
        $faq = ChatbotFaq::where('question', 'Can I request embroidery?')->firstOrFail();
        $this->assertSame(0, $faq->sort_order);
        $this->chat('Can I request embroidery?')->assertOk()->assertJsonPath('mode', 'basic')->assertJsonPath('reply', $faq->answer);
        $this->get(route('admin.faqs.edit', $faq))->assertOk()->assertSee($faq->question);
        $this->patch(route('admin.faqs.update', $faq), $this->faqInput(['answer' => 'Updated approved embroidery answer.']))->assertRedirect()->assertSessionHasNoErrors();
        $this->chat('I would like embroidery')->assertOk()->assertJsonPath('reply', 'Updated approved embroidery answer.');
        $this->patch(route('admin.faqs.update', $faq), $this->faqInput(['answer' => 'Inactive secret answer.', 'active' => 0]))->assertRedirect();
        $this->chat('Can I request embroidery?')->assertDontSee('Inactive secret answer.');
        $this->delete(route('admin.faqs.destroy', $faq))->assertRedirect(route('admin.settings'));
        $this->assertDatabaseMissing('chatbot_faqs', ['id' => $faq->id]);
        $this->chat('Can I request embroidery?')->assertDontSee('Updated approved embroidery answer.');
    }

    public function test_faq_validation_rejects_invalid_and_duplicate_entries_without_writes(): void
    {
        $this->login();
        $before = ChatbotFaq::count();
        $this->post(route('admin.faqs.store'), $this->faqInput(['question' => '', 'answer' => str_repeat('a', 2501), 'keywords' => str_repeat('k', 501), 'active' => 'invalid', 'sort_order' => -1]))
            ->assertSessionHasErrors(['question', 'answer', 'keywords', 'active', 'sort_order']);
        $this->post(route('admin.faqs.store'), $this->faqInput(['question' => ChatbotFaq::first()->question]))->assertSessionHasErrors('question');
        $this->assertSame($before, ChatbotFaq::count());
    }

    public function test_guests_customers_and_staff_cannot_manage_faqs_or_store_information(): void
    {
        $faq = ChatbotFaq::firstOrFail();
        $this->get(route('admin.settings'))->assertRedirect(route('login'));
        $this->post(route('admin.faqs.store'), $this->faqInput())->assertRedirect(route('login'));
        foreach (['customer', 'staff'] as $role) {
            $this->login($role);
            $this->get(route('admin.settings'))->assertForbidden();
            $this->get(route('admin.faqs.create'))->assertForbidden();
            $this->get(route('admin.faqs.edit', $faq))->assertForbidden();
            $this->get(route('admin.faqs.template'))->assertForbidden();
            $this->post(route('admin.faqs.store'), $this->faqInput())->assertForbidden();
            $this->patch(route('admin.faqs.update', $faq), $this->faqInput())->assertForbidden();
            $this->delete(route('admin.faqs.destroy', $faq))->assertForbidden();
            $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([['Question?', 'Answer', '', '1', '0']])])->assertForbidden();
            $this->post(route('admin.settings.save'), ['opening_hours' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertDatabaseCount('chatbot_faqs', 4);
        $this->assertSame('', app(StoreKnowledge::class)->info()['opening_hours']);
    }

    public function test_csv_import_supports_quoted_answers_optional_columns_and_active_status(): void
    {
        $this->login();
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([
            ['Can I request embroidery?', "Ask staff, then review the quotation.\nBring your design.", 'embroidery, stitching', '1', '20'],
            ['Can you stitch names?', 'Keep this inactive answer private.', 'names', '0', '30'],
        ])])->assertRedirect(route('admin.settings'))->assertSessionHasNoErrors();
        $active = ChatbotFaq::where('question', 'Can I request embroidery?')->firstOrFail();
        $this->assertSame("Ask staff, then review the quotation.\nBring your design.", $active->answer);
        $this->assertTrue($active->active);
        $this->assertFalse(ChatbotFaq::where('question', 'Can you stitch names?')->firstOrFail()->active);
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([['Where do I ask about logos?', 'Ask staff about a logo quotation.']], ['question', 'answer'])])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(ChatbotFaq::where('question', 'Where do I ask about logos?')->firstOrFail()->active);
    }

    public function test_csv_validation_is_atomic_for_invalid_rows_existing_duplicates_and_repeated_questions(): void
    {
        $this->login();
        $before = ChatbotFaq::count();
        foreach ([
            [['New question?', 'Approved answer', '', '1', '0'], ['Missing answer?', '', '', '1', '0']],
            [['New question?', 'Approved answer', '', '1', '0'], [ChatbotFaq::first()->question, 'Duplicate answer', '', '1', '0']],
            [['Same question?', 'First answer', '', '1', '0'], ['SAME QUESTION?', 'Second answer', '', '1', '0']],
            [['New question?', 'Approved answer', '', 'possibly', '0']],
        ] as $rows) {
            $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv($rows)])->assertSessionHasErrors('faq_csv');
            $this->assertSame($before, ChatbotFaq::count());
        }
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([['Question?', 'Answer']], ['question', 'unknown'])])->assertSessionHasErrors('faq_csv');
        $this->assertSame($before, ChatbotFaq::count());
    }

    public function test_csv_accepts_one_hundred_entries_and_rejects_larger_files_and_batches(): void
    {
        $this->login();
        $before = ChatbotFaq::count();
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = ['Import question '.$i.'?', 'Approved answer '.$i, '', '1', '0'];
        }
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv($rows)])->assertSessionHasErrors('faq_csv');
        $this->assertSame($before, ChatbotFaq::count());
        $this->post(route('admin.faqs.import'), ['faq_csv' => UploadedFile::fake()->create('large.csv', 1025, 'text/csv')])->assertSessionHasErrors('faq_csv');
        $this->assertSame($before, ChatbotFaq::count());
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv(array_slice($rows, 0, 100))])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 100, ChatbotFaq::count());
        $template = $this->get(route('admin.faqs.template'))->assertOk();
        $this->assertStringContainsString('question,answer,keywords,active,sort_order', $template->streamedContent());
    }

    public function test_store_information_has_separate_validated_fields_and_is_used_by_basic_chat(): void
    {
        $this->login();
        config(['shop.grok.key' => null]);
        $data = ['opening_hours' => 'Monday to Friday, 9 AM to 5 PM', 'pickup_address' => '123 Sample Street',
            'pickup_instructions' => 'Bring your order number.', 'support_email' => 'support@example.test', 'support_phone' => '09123456789', 'store_note' => 'Approved sample note.'];
        $this->post(route('admin.settings.save'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($data, app(StoreKnowledge::class)->info());
        $this->chat('What are your opening hours?')->assertOk()->assertSee($data['opening_hours']);
        $this->chat('Where is your pickup location?')->assertOk()->assertSee($data['pickup_address'])->assertSee($data['pickup_instructions']);
        $this->chat('How can I contact support?')->assertOk()->assertSee($data['support_email'])->assertSee($data['support_phone']);
        $this->post(route('admin.settings.save'), $data + ['ignored' => 'discarded'])->assertSessionHasNoErrors();
        $this->post(route('admin.settings.save'), ['support_email' => 'not-an-email', 'support_phone' => 'letters'])->assertSessionHasErrors(['support_email', 'support_phone']);
        $this->assertSame($data, app(StoreKnowledge::class)->info());
    }

    public function test_faq_library_is_paginated_and_searchable_by_status_and_keywords(): void
    {
        $this->login();
        for ($i = 1; $i <= 15; $i++) {
            ChatbotFaq::create($this->faqInput(['question' => 'Library question '.$i.'?', 'keywords' => 'embroidery-'.$i, 'active' => $i % 2 === 0, 'sort_order' => 50 + $i]));
        }
        $first = $this->get(route('admin.settings'))->assertOk()->assertViewHas('faqs', fn ($faqs) => $faqs->count() === 12 && $faqs->total() === 19);
        $first->assertSee('Next →');
        $this->get(route('admin.settings', ['page' => 2]))->assertViewHas('faqs', fn ($faqs) => $faqs->count() === 7);
        $this->get(route('admin.settings', ['faq_search' => 'embroidery-12', 'faq_status' => 'active']))
            ->assertOk()->assertSee('Library question 12?')->assertDontSee('Library question 11?');
        $this->get(route('admin.settings', ['faq_search' => 'embroidery-12', 'faq_status' => 'inactive']))->assertOk()->assertDontSee('Library question 12?');
    }

    public function test_basic_matching_uses_active_approved_faqs_and_does_not_guess_for_unrelated_questions(): void
    {
        config(['shop.grok.key' => null]);
        ChatbotFaq::create($this->faqInput(['answer' => 'Approved embroidery answer.']));
        ChatbotFaq::create($this->faqInput(['question' => 'Can you stitch names?', 'answer' => 'Inactive private answer.', 'keywords' => 'names', 'active' => false]));
        $this->chat('Can I request embroidery?')->assertJsonPath('reply', 'Approved embroidery answer.');
        $this->chat('Do you offer stitching?')->assertJsonPath('reply', 'Approved embroidery answer.');
        $this->chat('Galaxies are mysterious')->assertDontSee('Approved embroidery answer.');
        $this->chat('Can you stitch names?')->assertDontSee('Inactive private answer.');
    }

    public function test_approved_size_help_edits_and_bulk_return_questions_are_used_by_basic_chat(): void
    {
        config(['shop.grok.key' => null]);
        ChatbotFaq::where('question', 'Where can I see the size chart?')->firstOrFail()->update(['answer' => 'Check the chart on each product and ask staff for fit assistance.']);
        ChatbotFaq::create($this->faqInput(['question' => 'Can I return bulk orders?', 'answer' => 'Ask staff to review your return request.', 'keywords' => 'return bulk orders']));
        $this->chat('Where can I see the size chart?')->assertJsonPath('reply', 'Check the chart on each product and ask staff for fit assistance.');
        $this->chat('Can I return bulk orders?')->assertJsonPath('reply', 'Ask staff to review your return request.');
    }

    public function test_grok_receives_relevant_active_faqs_and_store_info_with_at_most_ten_entries(): void
    {
        config(['shop.grok.key' => 'test-grok', 'shop.grok.model' => 'test-model']);
        for ($i = 1; $i <= 15; $i++) {
            ChatbotFaq::create($this->faqInput(['question' => 'General library topic '.$i.'?', 'keywords' => '', 'sort_order' => 100 + $i]));
        }
        ChatbotFaq::create($this->faqInput(['question' => 'Can I request embroidery?', 'answer' => 'Relevant approved answer.', 'sort_order' => 999]));
        ChatbotFaq::create($this->faqInput(['question' => 'Hidden embargo question?', 'answer' => 'HIDDEN-DO-NOT-SEND', 'keywords' => 'embroidery', 'active' => false]));
        app(StoreKnowledge::class)->saveInfo(['opening_hours' => 'Confirmed test hours']);
        Http::fake([
            'api.x.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Approved AI reply.']]],
            ]),
        ]);
        $this->chat('Can I request embroidery?')->assertJsonPath('mode', 'grok');
        Http::assertSent(function ($request) {
            $content = $request['messages'][0]['content'];
            $context = json_decode(substr($content, strpos($content, 'Context: ') + 9), true);

            return count($context['approved_faqs']) === 10
                && $context['approved_faqs'][0]['answer'] === 'Relevant approved answer.'
                && $context['store_information']['opening_hours'] === 'Confirmed test hours'
                && ! str_contains($content, 'HIDDEN-DO-NOT-SEND');
        });
    }

    public function test_faqs_cannot_override_pricing_verified_payment_rules_or_private_order_status(): void
    {
        config(['shop.grok.key' => null]);
        ChatbotFaq::create($this->faqInput(['question' => 'What is the bulk price?', 'answer' => 'Wrong discount answer.', 'keywords' => 'bulk']));
        ChatbotFaq::create($this->faqInput(['question' => 'Does proof of payment confirm my payment?', 'answer' => 'Wrong payment answer.', 'keywords' => 'proof of payment']));
        $owner = User::factory()->create();
        $privateOrder = $this->order($owner);
        $this->chat('What is the bulk price?')->assertSee('5%')->assertDontSee('Wrong discount answer.');
        $this->chat('Does proof of payment confirm my payment?')->assertSee('verified PayMongo')->assertDontSee('Wrong payment answer.');
        $this->login('customer');
        $this->chat('Track '.$privateOrder->number)->assertDontSee('awaiting payment');
        $this->chat('Show staff orders', 'staff')->assertForbidden();
        $this->actingAs($owner);
        $this->chat('Track '.$privateOrder->number)->assertSee('awaiting payment')->assertSee('pending');
    }

    public function test_payment_and_order_facts_do_not_depend_on_grok_output(): void
    {
        config(['shop.grok.key' => 'test-grok', 'shop.grok.model' => 'test-model']);
        Http::fake([
            'api.x.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Unverified payment claim.']]],
            ]),
        ]);
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->actingAs($owner);
        $this->chat('Track '.$order->number)->assertJsonPath('mode', 'store')->assertSee('pending')->assertDontSee('Unverified payment claim.');
        $this->chat('How are payments confirmed?')->assertJsonPath('mode', 'store')->assertSee('verified PayMongo');
        $this->chat('What is the bulk price?')->assertJsonPath('mode', 'store')->assertSee('5%');
        Http::assertNothingSent();
    }
}
