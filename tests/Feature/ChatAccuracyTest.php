<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogBatch;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.grok.key' => 'test-grok', 'shop.grok.model' => 'test-model']);
        Http::fake(['api.x.ai/*' => Http::response(['choices' => [['message' => ['content' => 'AI answer should not establish commerce facts.']]]])]);
        app(CatalogBatch::class)->import();
    }

    private function chat(string $message, string $side = 'customer')
    {
        return $this->postJson(route('chat'), ['message' => $message, 'side' => $side]);
    }

    private function product(string $slug = 'dannon-with-collar'): Product
    {
        return Product::where('slug', $slug)->firstOrFail();
    }

    private function faq(string $question, string $answer): void
    {
        ChatbotFaq::create(['question' => $question, 'answer' => $answer, 'active' => true]);
    }

    public function test_nonexistent_color_size_combination_is_not_inferred_from_a_chart_or_other_colors(): void
    {
        $this->product()->variants()->where('size', '5XL')->where('color', 'Red')->update(['stock' => 12, 'reserved' => 3]);
        $this->faq('Is Dannon collar White 5XL available?', 'Invented FAQ says White 5XL is available.');
        $this->chat('Is Dannon collar White 5XL available?')->assertOk()->assertJsonPath('mode', 'store')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '5XL / White is not an offered variant'))->assertDontSee('Invented FAQ');
        $this->chat('Is Dannon collar Red 5XL available?')->assertJsonPath('mode', 'store')->assertSee('9 pieces currently available');
        Http::assertNothingSent();
    }

    public function test_availability_is_for_the_chosen_variant_and_excludes_reserved_stock(): void
    {
        $this->product()->variants()->where('size', 'S')->where('color', 'Red')->update(['stock' => 8, 'reserved' => 8]);
        $this->product()->variants()->where('size', 'S')->where('color', 'Teal')->update(['stock' => 20, 'reserved' => 6]);
        $this->chat('How much stock is there for Dannon with collar S Red?')->assertJsonPath('mode', 'store')->assertSee('currently out of stock (0 pieces available)');
        $this->chat('Is Dannon with collar S Teal in stock?')->assertJsonPath('mode', 'store')->assertSee('14 pieces currently available');
        $this->chat('Do you have Dannon with collar S Teal?')->assertJsonPath('mode', 'store')->assertSee('14 pieces currently available');
        config(['shop.grok.key' => null]);
        $this->chat('Is Dannon with collar S Teal in stock?')->assertJsonPath('mode', 'basic')->assertSee('14 pieces currently available');
        Http::assertNothingSent();
    }

    public function test_variant_prices_and_bulk_subtotal_are_database_facts_not_faq_or_ai_claims(): void
    {
        $this->faq('What is the price of Dannon collar S White for 101 pieces?', 'Invented FAQ says one peso.');
        $this->chat('What is the price of Dannon collar S White for 101 pieces?')->assertOk()->assertJsonPath('mode', 'store')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'Retail / SRP (1–5): ₱202.00'))
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'Wholesale (6–50): ₱192.00'))
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'Re-seller (51–100): ₱187.00'))
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '₱17,942.65 clothing subtotal (Bulk)'))
            ->assertSee('Delivery is separate')->assertDontSee('Invented FAQ');
        $this->faq('Is 6 pieces retail or wholesale?', 'Invented FAQ says 6 pieces use retail.');
        $this->chat('Is 6 pieces retail or wholesale?')->assertJsonPath('mode', 'store')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '6–50 Wholesale'))->assertDontSee('Invented FAQ');
        Http::assertNothingSent();
    }

    public function test_missing_or_ambiguous_product_size_and_color_require_clarification(): void
    {
        foreach (['How much is Dannon?', 'Is Dannon collar White available?', 'What is the price for Dannon collar S?',
            'Is Dannon collar S or M Red available?', 'Is Dannon collar S Red or Teal available?', 'Is Dannon collar S White or Purple available?', 'Is Dannon collar M or 6XL White available?',
            'What is the price for Dannon collar S White and Thanos S White?', 'What is the price for Dannon collar S Purple?'] as $message) {
            $this->chat($message)->assertOk()->assertJsonPath('mode', 'store')->assertSee('specify')->assertDontSee('pieces currently available');
        }
        Http::assertNothingSent();
    }

    public function test_longer_color_names_and_kids_style_names_resolve_without_false_matches(): void
    {
        $this->product()->variants()->where('size', 'S')->where('color', 'Light Yellow')->update(['stock' => 7]);
        $this->product()->variants()->where('size', 'S')->where('color', 'Yellow')->update(['stock' => 11]);
        $this->chat('Is Dannon polo S Light Yellow available?')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'S / Light Yellow: 7 pieces currently available'));
        $this->chat('Is Dannon polo S Yellow available?')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'S / Yellow: 11 pieces currently available'));
        $this->chat('Is Dannon round neck kids size 14 White available?')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'DANNON - ROUND NECK / V-NECK KIDS') && str_contains($reply, '14 / White is not an offered variant'));
        $this->chat("I'm interested in Dannon collar White, what is the price?")->assertSee('please specify one size and one color');
        Http::assertNothingSent();
    }

    public function test_grok_catalog_context_has_actual_scoped_variant_prices_and_stock(): void
    {
        $this->product()->variants()->where('size', 'S')->where('color', 'White')->update(['stock' => 15, 'reserved' => 4]);
        $this->chat('Help me choose a fit for Dannon collar size S White')->assertOk()->assertJsonPath('mode', 'grok');
        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true);
            $catalog = $context['catalog'];

            return count($catalog) === 1 && $catalog[0]['name'] === 'DANNON - WITH COLLAR'
                && $catalog[0]['variants_complete'] === true && $catalog[0]['matching_variant_count'] === 1
                && $catalog[0]['variants'] === [['size' => 'S', 'color' => 'White', 'color_group' => 'WHITE', 'available_pieces' => 11,
                    'retail_price' => 20200, 'wholesale_price' => 19200, 'reseller_price' => 18700]]
                && $catalog[0]['price_unit'] === 'integer centavos per piece'
                && str_contains($context['policy'], 'Never infer availability or prices from another size/color');
        });
    }

    public function test_grok_context_is_bounded_and_does_not_treat_omitted_variants_as_unavailable(): void
    {
        $this->chat('Recommend options for a uniform')->assertJsonPath('mode', 'grok');
        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true);

            return count($context['catalog']) === 3
                && collect($context['catalog'])->every(fn ($product) => count($product['variants']) <= 40)
                && collect($context['catalog'])->every(fn ($product) => $product['variants_complete'] === false)
                && str_contains($context['policy'], 'omitted variants are unknown, not unavailable')
                && strlen(json_encode($context['catalog'])) < 30000;
        });
    }

    public function test_hidden_products_and_missing_information_are_not_exposed_as_available(): void
    {
        $this->product()->update(['active' => false]);
        $this->chat('Is Dannon collar S White available?')->assertJsonPath('mode', 'store')->assertSee('specify one product name')->assertDontSee('₱202.00');
        $this->chat('Recommend Thanos')->assertJsonPath('mode', 'grok');
        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];
            $context = json_decode(substr($system, strpos($system, 'Context: ') + 9), true);

            return count($context['catalog']) === 1 && $context['catalog'][0]['name'] === 'THANOS'
                && ! str_contains(json_encode($context['catalog']), 'DANNON - WITH COLLAR');
        });
    }

    public function test_explicit_order_and_payment_status_remain_private_and_authoritative_over_faqs(): void
    {
        $owner = User::factory()->create();
        $variant = $this->product()->variants()->where('size', 'S')->where('color', 'White')->firstOrFail();
        $variant->update(['stock' => 10]);
        $order = app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $owner->id);
        $this->faq('Has my order been paid?', 'Invented FAQ says it is paid.');
        $this->chat('Has my order been paid?')->assertJsonPath('mode', 'store')->assertSee('Sign in and share your full order number')->assertDontSee('Invented FAQ');
        $this->actingAs(User::factory()->create());
        $this->chat('Payment status '.$order->number)->assertJsonPath('mode', 'store')->assertDontSee('awaiting payment');
        $this->actingAs($owner);
        $this->chat('Track my order '.$order->number)->assertJsonPath('mode', 'store')->assertSee('awaiting payment; payment pending');
        $this->faq('Does proof of payment confirm my payment?', 'Invented FAQ says a screenshot is enough.');
        $this->chat('Does proof of payment confirm my payment?')->assertJsonPath('mode', 'store')->assertSee('verified PayMongo payment notification')->assertDontSee('Invented FAQ');
        Http::assertNothingSent();
    }
}
