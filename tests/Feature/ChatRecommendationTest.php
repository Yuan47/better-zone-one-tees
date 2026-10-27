<?php

namespace Tests\Feature;

use App\Models\ChatbotFaq;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.groq.key' => 'fixture-groq-key', 'shop.groq.model' => 'test-model']);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => function ($request) {
            $content = $request['messages'][1]['content'] === 'Suggest a greeting for a new visitor.' ? 'Welcome to Zone One Tees!'
                : 'Soft cotton blend. Fits comfortably under layers. Invented Purple 5XL is available.';

            return Http::response(['choices' => [['message' => ['content' => $content]]]]);
        }]);
        ChatbotFaq::query()->delete();
    }

    private function product(): Product
    {
        $product = Product::create(['name' => 'DANNON - WITH COLLAR', 'slug' => 'dannon-with-collar',
            'category' => 'Shirts', 'description' => '', 'active' => true,
            'size_chart' => [['size' => '5XL', 'width' => 26.5, 'length' => 34]]]);
        $product->variants()->create(['sku' => 'REC-S-WHITE', 'size' => 'S', 'color' => 'White',
            'retail_price' => 20200, 'wholesale_price' => 19200, 'reseller_price' => 18700,
            'stock' => 10, 'reserved' => 3]);

        return $product;
    }

    public static function shoppingQuestions(): iterable
    {
        yield 'comfortable school suggestion' => ['Suggest something comfortable for school.'];
        yield 'choose comfortable' => ['Help me choose something comfortable.'];
        yield 'uniform recommendation' => ['Recommend options for a uniform'];
        yield 'named product' => ['Help me choose Dannon collar S White'];
        yield 'what to wear' => ['What should I wear for school?'];
        yield 'named product description' => ['Tell me about Dannon collar S White'];
        yield 'named product material' => ['Is Dannon collar cotton?'];
        yield 'general fabric question' => ['What fabric are the shirts made from?'];
        yield 'general softness question' => ['Which shirt feels soft?'];
        yield 'best shirt' => ['Which shirt is best for school?'];
    }

    #[DataProvider('shoppingQuestions')]
    public function test_shopping_recommendations_use_catalog_facts_without_generated_material_or_comfort_claims(string $message): void
    {
        $this->product();
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => $message])->assertOk()
            ->assertJsonPath('mode', 'store')->assertSee('DANNON - WITH COLLAR')
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'S / White'))
            ->assertSee('7 pieces currently available')->assertDontSee('Soft cotton blend')->assertDontSee('Fits comfortably')
            ->assertDontSee('Purple')->assertDontSee('5XL')->assertSee('ask store staff');
        Http::assertNothingSent();
    }

    public function test_recommendations_do_not_turn_chart_sizes_or_other_colors_into_offered_variants(): void
    {
        $this->product();
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Suggest Dannon collar 5XL Purple'])->assertOk()
            ->assertJsonPath('mode', 'store')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'no currently available matching size/color'))
            ->assertDontSee('7 pieces currently available')->assertDontSee('Invented');
        Http::assertNothingSent();
    }

    public function test_fully_reserved_or_hidden_options_are_not_recommended_as_available(): void
    {
        $product = $this->product();
        $product->variants()->update(['reserved' => 10]);
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Recommend Dannon collar'])->assertOk()
            ->assertJsonPath('mode', 'store')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'no currently available matching size/color'))
            ->assertDontSee('10 pieces currently available');
        $product->update(['active' => false]);
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Suggest something comfortable for school.'])->assertOk()
            ->assertJsonPath('mode', 'store')->assertSee('There are no active products')->assertDontSee('DANNON');
        Http::assertNothingSent();
    }

    public function test_approved_faq_still_precedes_a_general_shopping_recommendation(): void
    {
        $this->product();
        ChatbotFaq::create(['question' => 'Can you suggest a shirt for a school uniform?', 'answer' => 'Please ask staff to check your school requirements.', 'active' => true]);
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Can you suggest a shirt for a school uniform?'])->assertOk()
            ->assertJsonPath('mode', 'store')->assertJsonPath('reply', 'Please ask staff to check your school requirements.');
        Http::assertNothingSent();
    }

    public function test_basic_mode_and_staff_recommendations_use_the_same_grounded_answer(): void
    {
        $this->product();
        config(['shop.groq.key' => '']);
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Recommend a shirt'])->assertOk()
            ->assertJsonPath('mode', 'basic')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'S / White'))->assertDontSee('Soft cotton blend');
        config(['shop.groq.key' => 'fixture-groq-key']);
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->postJson(route('chat'), ['side' => 'staff', 'message' => 'Recommend a shirt'])->assertOk()
            ->assertJsonPath('mode', 'store')->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'S / White'))->assertDontSee('Soft cotton blend');
        Http::assertNothingSent();
    }

    public function test_suggestions_outside_shopping_can_still_use_the_provider(): void
    {
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Suggest a greeting for a new visitor.'])->assertOk()
            ->assertJsonPath('mode', 'groq')->assertJsonPath('reply', 'Welcome to Zone One Tees!');
        Http::assertSentCount(1);
    }
}
