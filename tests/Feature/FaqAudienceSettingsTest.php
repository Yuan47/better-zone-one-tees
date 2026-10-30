<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChatbotFaq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FaqAudienceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function input(array $overrides = []): array
    {
        return $overrides + ['question' => 'How do I ask about a custom print?', 'answer' => 'Ask the store about your design.',
            'keywords' => 'custom print', 'active' => 1, 'sort_order' => 3];
    }

    private function csv(array $rows, array $headers = ['question', 'answer', 'audience']): UploadedFile
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('audience-faqs.csv', $contents)->mimeType('text/csv');
    }

    public function test_owner_can_choose_and_change_audience_and_old_forms_default_to_customer(): void
    {
        $this->owner();
        $this->post(route('admin.faqs.store'), $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $faq = ChatbotFaq::where('question', 'How do I ask about a custom print?')->firstOrFail();
        $this->assertSame('customer', $faq->audience);

        foreach (['staff', 'owner', 'customer'] as $audience) {
            $this->patch(route('admin.faqs.update', $faq), $this->input(['audience' => $audience]))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($audience, $faq->fresh()->audience);
            $this->assertSame(['fields' => ['audience']], ActivityLog::where('action', 'faq.updated')->latest('id')->firstOrFail()->metadata);
        }
        $this->patch(route('admin.faqs.update', $faq), $this->input(['audience' => 'owner']))->assertRedirect();
        $this->patch(route('admin.faqs.update', $faq), $this->input(['answer' => 'Updated owner guidance.']))->assertSessionHasNoErrors();
        $this->assertSame('owner', $faq->fresh()->audience, 'An older form must not make an internal FAQ public.');

        $this->post(route('admin.faqs.store'), $this->input(['question' => 'New internal answer?', 'audience' => 'staff']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('chatbot_faqs', ['question' => 'New internal answer?', 'audience' => 'staff']);
    }

    public function test_invalid_audiences_are_rejected_without_changing_the_saved_answer(): void
    {
        $this->owner();
        $faq = ChatbotFaq::create($this->input(['audience' => 'owner']));
        $before = ChatbotFaq::count();
        foreach (['admin', 'everyone', '', 'CUSTOMER'] as $audience) {
            $this->post(route('admin.faqs.store'), $this->input(['question' => 'Invalid audience question?', 'audience' => $audience]))
                ->assertSessionHasErrors('audience');
            $this->patch(route('admin.faqs.update', $faq), $this->input(['answer' => 'Do not save this.', 'audience' => $audience]))
                ->assertSessionHasErrors('audience');
            $this->assertSame($before, ChatbotFaq::count());
            $this->assertSame('owner', $faq->fresh()->audience);
            $this->assertSame('Ask the store about your design.', $faq->fresh()->answer);
        }
    }

    public function test_csv_import_accepts_explicit_audiences_and_defaults_missing_or_blank_to_customer(): void
    {
        $this->owner();
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([
            ['Public CSV question?', 'Public answer.', 'customer'],
            ['Staff CSV question?', 'Staff answer.', 'staff'],
            ['Owner CSV question?', 'Owner answer.', 'owner'],
            ['Blank audience CSV question?', 'Default public answer.', ''],
        ])])->assertRedirect()->assertSessionHasNoErrors();
        foreach (['Public' => 'customer', 'Staff' => 'staff', 'Owner' => 'owner', 'Blank audience' => 'customer'] as $label => $audience) {
            $this->assertDatabaseHas('chatbot_faqs', ['question' => $label.' CSV question?', 'audience' => $audience]);
        }
        $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([
            ['Legacy CSV question?', 'No audience column.'],
        ], ['question', 'answer'])])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('chatbot_faqs', ['question' => 'Legacy CSV question?', 'audience' => 'customer']);
    }

    public function test_csv_import_rejects_an_invalid_audience_atomically(): void
    {
        $this->owner();
        $before = ChatbotFaq::count();
        foreach (['admin', 'public', 'STAFF'] as $invalid) {
            $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([
                ['Valid CSV row?', 'Valid internal answer.', 'staff'],
                ['Invalid CSV row?', 'Invalid audience.', $invalid],
            ])])->assertSessionHasErrors('faq_csv');
            $this->assertSame($before, ChatbotFaq::count());
        }
    }

    public function test_owner_forms_library_and_csv_template_explain_audience_boundaries(): void
    {
        $this->owner();
        $this->get(route('admin.faqs.create'))->assertOk()
            ->assertSee('name="audience"', false)->assertSee('Customer · public shopping help')
            ->assertSee('Staff · staff and owner workspace only')->assertSee('Owner · owner workspace only');
        $internal = ChatbotFaq::create($this->input(['audience' => 'owner']));
        $this->get(route('admin.faqs.edit', $internal))->assertOk()->assertSee('value="owner" selected', false);
        $this->get(route('admin.settings', ['faq_search' => 'custom print']))->assertOk()
            ->assertSee('Audience:')->assertSee('Owner · owner workspace only')
            ->assertSee('Existing custom FAQs start as Customer')->assertSee('audience. Audience must be customer');
        $content = $this->get(route('admin.faqs.template'))->assertOk()->streamedContent();
        $this->assertStringContainsString('question,answer,keywords,active,sort_order,audience', $content);
        $this->assertStringContainsString(',customer', $content);
        Http::assertNothingSent();
    }

    public function test_staff_and_customers_cannot_manage_an_internal_faq_audience(): void
    {
        $faq = ChatbotFaq::create($this->input(['audience' => 'owner']));
        foreach (['staff', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->patch(route('admin.faqs.update', $faq), $this->input(['audience' => 'customer']))->assertForbidden();
            $this->post(route('admin.faqs.import'), ['faq_csv' => $this->csv([['Unauthorized?', 'Answer.', 'customer']])])->assertForbidden();
        }
        $this->assertSame('owner', $faq->fresh()->audience);
        $this->assertDatabaseMissing('chatbot_faqs', ['question' => 'Unauthorized?']);
    }

    public function test_additive_migration_preserves_answers_toggles_and_custom_scopes(): void
    {
        $migration = require database_path('migrations/2026_10_19_000016_add_faq_audiences_and_how_to_guides.php');
        $legacy = ChatbotFaq::where('question', 'How is a staff account created?')->firstOrFail();
        $legacy->update(['answer' => 'Owner-edited private staff guidance.', 'keywords' => 'private staff rules',
            'active' => false, 'sort_order' => 77, 'audience' => 'customer']);
        $beforeLegacy = $legacy->fresh()->getAttributes();
        $custom = ChatbotFaq::create($this->input(['question' => 'Existing custom guidance?', 'active' => false]));
        $edited = ChatbotFaq::where('question', 'How do I create a customer account?')->firstOrFail();
        $edited->update(['answer' => 'Owner-edited account answer.', 'active' => false, 'audience' => 'staff']);
        $beforeEdited = $edited->fresh()->getAttributes();
        $count = ChatbotFaq::count();

        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame($count, ChatbotFaq::count());
        $this->assertSame(array_replace($beforeLegacy, ['audience' => 'owner']), $legacy->fresh()->getAttributes());
        $this->assertSame($beforeEdited, $edited->fresh()->getAttributes());
        $this->assertSame('customer', $custom->fresh()->audience);
        $this->assertFalse($custom->fresh()->active);
        $this->assertSame('customer', ChatbotFaq::where('question', 'What are the quantity pricing tiers?')->firstOrFail()->audience);
    }

    public function test_new_guides_have_explicit_scopes_and_short_numbered_steps(): void
    {
        $guides = ChatbotFaq::whereBetween('sort_order', [300, 699])->get();
        $this->assertCount(29, $guides);
        $this->assertSame(['customer' => 13, 'owner' => 7, 'staff' => 9], $guides->countBy('audience')->sortKeys()->all());
        foreach ($guides as $guide) {
            $this->assertMatchesRegularExpression('/^1\. .+\n2\. .+\n3\. .+\n4\. .+$/u', $guide->answer, $guide->question);
            $this->assertLessThanOrEqual(2500, mb_strlen($guide->answer));
            $this->assertTrue($guide->active);
        }
        $this->assertStringContainsString('never', mb_strtolower($guides->firstWhere('question', 'How do I enter my email verification code?')->answer));
        $this->assertStringContainsString('only for this setup', $guides->firstWhere('question', 'How does the owner activate a staff account with its setup code?')->answer);
    }

    public function test_registration_guide_refresh_only_updates_the_exact_old_default_and_keeps_its_other_settings(): void
    {
        $previous = "1. Open Sign in, then choose Create an account.\n2. Enter your name, email, and a password of at least 10 characters; enter the same password again.\n3. Choose Create account. If email verification is required, enter the latest code on the verification page.\n4. Keep your password and personal verification code private. The assistant never needs them.";
        $faq = ChatbotFaq::where('question', 'How do I create a customer account?')->firstOrFail();
        $faq->update(['answer' => $previous, 'active' => false, 'audience' => 'staff', 'keywords' => 'custom words', 'sort_order' => 77]);
        $before = $faq->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_27_000018_refresh_default_customer_registration_guide.php');
        $migration->up();
        $updated = $faq->fresh();
        $this->assertStringContainsString('full name, a unique username, email', $updated->answer);
        $this->assertStringContainsString('at least 10 characters', $updated->answer);
        $this->assertStringContainsString('same password again', $updated->answer);
        $this->assertStringContainsString('latest emailed code only on the verification page', $updated->answer);
        $this->assertStringContainsString('verified Google email', $updated->answer);
        $this->assertStringContainsString('creates a customer account', $updated->answer);
        $this->assertSame(array_replace($before, ['answer' => $updated->answer]), $updated->getAttributes());
        $migration->up();
        $migration->down();
        $this->assertSame($updated->getAttributes(), $faq->fresh()->getAttributes());

        foreach (['Owner-edited custom account steps.', str_replace('your name', 'your NAME', $previous)] as $edited) {
            $faq->update(['answer' => $edited]);
            $beforeEdit = $faq->fresh()->getAttributes();
            $migration->up();
            $this->assertSame($beforeEdit, $faq->fresh()->getAttributes());
        }
        $faq->delete();
        $migration->up();
        $this->assertDatabaseMissing('chatbot_faqs', ['question' => 'How do I create a customer account?']);
    }
}
