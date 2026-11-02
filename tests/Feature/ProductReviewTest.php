<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(string $slug = 'review-shirt'): Product
    {
        $product = Product::create(['name' => 'Review Shirt', 'slug' => $slug, 'category' => '', 'description' => 'Plain clothing.', 'active' => true]);
        foreach (['Red', 'White'] as $color) {
            $product->variants()->create(['sku' => $slug.'-'.$color, 'size' => 'M', 'color' => $color, 'stock' => 500,
                'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        }

        return $product;
    }

    private function reviewedOrder(Product $product, int $rating = 5, string $comment = '', ?User $buyer = null, bool $twoVariants = false): Order
    {
        $buyer ??= User::factory()->create(['role' => 'customer', 'name' => 'Alexandra Private Surname']);
        $cart = $product->variants->take($twoVariants ? 2 : 1)->mapWithKeys(fn ($variant) => [$variant->id => 1])->all();
        $flow = app(OrderFlow::class);
        $order = $flow->reserve($cart, ['recipient' => 'Private Recipient', 'phone' => '09123456789',
            'address' => 'Private Street', 'delivery_method' => 'pickup', 'shipping' => 0], $buyer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_review_'.$order->id));
        $order->refresh()->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
        $order->review()->create(['user_id' => $buyer->id, 'rating' => $rating, 'comment' => $comment]);

        return $order->fresh();
    }

    public function test_product_without_ratings_has_an_honest_empty_state_and_inactive_product_stays_private(): void
    {
        $product = $this->product();
        $this->get(route('product', $product))->assertOk()->assertSeeText('No customer ratings yet')
            ->assertDontSeeText('Average order rating')->assertDontSeeText('Verified purchase');
        $product->update(['active' => false]);
        $this->get(route('product', $product))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_real_receipt_confirmation_publishes_stars_only_rating_below_details(): void
    {
        $product = $this->product();
        $order = $this->reviewedOrder($product);
        $order->review()->delete();
        $order->update(['status' => 'ready', 'customer_confirmed_at' => null]);
        $this->actingAs($order->user)->post(route('orders.receipt', $order), ['received' => '1', 'rating' => 4])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('product', $product))->assertOk()->assertSeeInOrder(['Size chart', 'Customer reviews'], false)
            ->assertSeeText('4.0')->assertSeeText('Verified purchase')->assertSeeText('4 / 5 · Order rating')
            ->assertSeeText('This customer left a star rating without a written review.');
        $this->assertDatabaseCount('order_reviews', 1);
        Http::assertNothingSent();
    }

    public function test_multiple_variants_count_once_and_renaming_a_product_does_not_lose_reviews(): void
    {
        $product = $this->product();
        $this->reviewedOrder($product, 5, 'A comfortable purchase.', twoVariants: true);
        $product->update(['name' => 'New Product Name', 'slug' => 'new-product-name']);
        $response = $this->get(route('product', $product))->assertOk();
        $response->assertViewHas('reviewCount', 1)->assertViewHas('averageRating', 5.0)
            ->assertSeeText('Red / M, White / M')->assertSeeText('A comfortable purchase.');
        $this->assertSame(1, substr_count($response->getContent(), 'class="product-review-card"'));
    }

    public function test_only_paid_customer_confirmed_completed_orders_belonging_to_the_reviewer_are_public(): void
    {
        $product = $this->product();
        $good = $this->reviewedOrder($product, 5, 'A real confirmed purchase.');
        $pending = $this->reviewedOrder($product, 1, 'UNPAID_REVIEW');
        $pending->update(['payment_status' => 'partially_paid']);
        $unconfirmed = $this->reviewedOrder($product, 1, 'UNCONFIRMED_REVIEW');
        $unconfirmed->update(['customer_confirmed_at' => null]);
        $canceled = $this->reviewedOrder($product, 1, 'CANCELED_REVIEW');
        $canceled->update(['status' => 'cancelled']);
        $mismatch = $this->reviewedOrder($product, 1, 'MISMATCHED_BUYER');
        $mismatch->review()->update(['user_id' => $good->user_id]);
        $this->reviewedOrder($product, 1, 'STAFF_REVIEW', User::factory()->create(['role' => 'staff']));
        $missingPayment = $this->reviewedOrder($product, 1, 'UNVERIFIED_PAYMENT');
        $missingPayment->update(['payment_id' => null]);
        $invalidStars = $this->reviewedOrder($product, 1, 'INVALID_RATING');
        $invalidStars->review()->update(['rating' => 9]);
        $otherProduct = $this->product('another-shirt');
        $this->reviewedOrder($otherProduct, 1, 'OTHER_PRODUCT_REVIEW');
        $response = $this->get(route('product', $product))->assertOk()->assertViewHas('reviewCount', 1)
            ->assertSeeText('A real confirmed purchase.');
        foreach (['UNPAID_REVIEW', 'UNCONFIRMED_REVIEW', 'CANCELED_REVIEW', 'MISMATCHED_BUYER', 'STAFF_REVIEW', 'UNVERIFIED_PAYMENT', 'INVALID_RATING', 'OTHER_PRODUCT_REVIEW'] as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    public function test_reviews_escape_untrusted_text_and_do_not_publish_buyer_or_invoice_details(): void
    {
        $product = $this->product();
        $buyer = User::factory()->create(['role' => 'customer', 'name' => 'Alexandra Private Surname', 'email' => 'private-buyer@example.test']);
        $order = $this->reviewedOrder($product, 2, '<script>alert("unsafe")</script>', $buyer);
        $response = $this->get(route('product', $product))->assertOk()->assertSeeText('A***')
            ->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false);
        foreach ([$buyer->name, $buyer->email, $order->number, $order->phone, $order->address, $order->payment_id] as $private) {
            $response->assertDontSee($private);
        }
    }

    public function test_one_mixed_order_rating_appears_on_both_products_with_only_their_own_variants(): void
    {
        $first = $this->product();
        $second = $this->product('another-shirt');
        $secondVariant = $second->variants()->where('color', 'Red')->firstOrFail();
        $secondVariant->update(['color' => 'Gold', 'size' => 'XL']);
        $buyer = User::factory()->create(['role' => 'customer']);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$first->variants->first()->id => 1, $secondVariant->id => 1],
            ['recipient' => 'Private Recipient', 'phone' => '09123456789', 'address' => 'Private Street',
                'delivery_method' => 'pickup', 'shipping' => 0], $buyer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_mixed_'.$order->id));
        $order->refresh()->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
        $order->review()->create(['user_id' => $buyer->id, 'rating' => 3, 'comment' => 'My rating covers the whole order.']);
        $this->get(route('product', $first))->assertOk()->assertViewHas('reviewCount', 1)
            ->assertSeeText('Purchased: Red / M')->assertDontSeeText('Purchased: Gold / XL')
            ->assertSeeText('My rating covers the whole order.');
        $this->get(route('product', $second))->assertOk()->assertViewHas('reviewCount', 1)
            ->assertSeeText('Purchased: Gold / XL')->assertDontSeeText('Purchased: Red / M')
            ->assertSeeText('My rating covers the whole order.');
        $this->assertDatabaseCount('order_reviews', 1);
    }

    public function test_rating_filter_keeps_overall_summary_and_paginated_newest_first_results(): void
    {
        $product = $this->product();
        foreach (range(1, 7) as $index) {
            $order = $this->reviewedOrder($product, 5, 'FIVE_STAR_REVIEW_'.$index);
            $order->review()->update(['created_at' => now()->subDays(8 - $index)]);
        }
        $this->reviewedOrder($product, 1, 'ONE_STAR_REVIEW');
        $url = route('product', $product);
        $first = $this->get($url.'?rating=5')->assertOk()->assertViewHas('reviewCount', 8)
            ->assertViewHas('averageRating', 4.5)->assertSeeInOrder(['FIVE_STAR_REVIEW_7', 'FIVE_STAR_REVIEW_6', 'FIVE_STAR_REVIEW_5'])
            ->assertDontSee('ONE_STAR_REVIEW')->assertDontSee('FIVE_STAR_REVIEW_1')
            ->assertSee('rating=5', false)->assertSee('#product-reviews', false);
        $this->assertCount(5, $first->viewData('reviews')->items());
        $this->get($url.'?rating=5&reviews_page=2')->assertOk()->assertViewHas('reviewCount', 8)
            ->assertSee('FIVE_STAR_REVIEW_2')->assertSee('FIVE_STAR_REVIEW_1')->assertDontSee('FIVE_STAR_REVIEW_7');
        $this->get($url.'?rating=2')->assertOk()->assertSeeText('No 2-star ratings yet.')->assertDontSee('FIVE_STAR_REVIEW_7');
        $this->get($url.'?rating[]=5')->assertOk()->assertViewHas('ratingFilter', null)->assertViewHas('reviewCount', 8);
    }

    public function test_refund_does_not_hide_an_existing_negative_customer_review(): void
    {
        $product = $this->product();
        $order = $this->reviewedOrder($product, 1, 'The clothing was not suitable for me.');
        $order->refunds()->create(['requested_by' => $order->user_id, 'reason' => 'The clothing was not suitable.', 'status' => 'confirmed', 'requested_amount' => $order->total, 'amount' => $order->total]);
        $this->get(route('product', $product))->assertOk()->assertViewHas('reviewCount', 1)
            ->assertSeeText('1 / 5 · Order rating')->assertSeeText('The clothing was not suitable for me.');
    }
}
