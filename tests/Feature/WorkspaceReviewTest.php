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

class WorkspaceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(string $slug = 'review-workspace-shirt', string $name = 'Workspace Shirt'): Product
    {
        $product = Product::create(['name' => $name, 'slug' => $slug, 'category' => '', 'description' => 'Test clothing.', 'active' => true]);
        foreach (['Red', 'White'] as $color) {
            $product->variants()->create(['sku' => $slug.'-'.$color, 'size' => 'M', 'color' => $color, 'stock' => 500,
                'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        }

        return $product;
    }

    private function order(Product $product, int $rating = 5, string $comment = '', bool $twoVariants = false): Order
    {
        $customer = User::factory()->create(['role' => 'customer', 'name' => 'Alexandra Private Surname']);
        $cart = $product->variants->take($twoVariants ? 2 : 1)->mapWithKeys(fn ($variant) => [$variant->id => 1])->all();
        $flow = app(OrderFlow::class);
        $order = $flow->reserve($cart, ['recipient' => 'Private Recipient', 'phone' => '09123456789', 'address' => 'Private Home Address',
            'delivery_method' => 'pickup', 'shipping' => 0], $customer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_workspace_'.$order->id));
        $order->refresh()->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
        $order->review()->create(['user_id' => $customer->id, 'rating' => $rating, 'comment' => $comment]);

        return $order->fresh();
    }

    public function test_reviews_are_restricted_to_signed_in_staff_and_owner(): void
    {
        $this->get('/admin/reviews')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/reviews?role=admin')->assertForbidden();
    }

    public function test_staff_and_owner_see_one_review_per_order_with_its_product_variants_and_no_private_profile_data(): void
    {
        $product = $this->product();
        $order = $this->order($product, 4, 'Comfortable clothing.', true);
        foreach (['staff', 'admin'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/reviews')->assertOk()
                ->assertViewHas('reviewCount', 1)->assertSeeText('Workspace Shirt')->assertSeeText('Red / M, White / M')
                ->assertSeeText('Comfortable clothing.')->assertSeeText('A***')->assertSeeText('4 / 5 · Order rating')
                ->assertSee(route('orders.show', $order), false)->assertSee(route('admin.reviews.index'), false)
                ->assertDontSee('Delete review')->assertDontSee('Edit review');
            foreach ([$order->user->name, $order->user->email, $order->address, $order->phone, $order->payment_id] as $private) {
                $response->assertDontSee($private);
            }
            $this->assertCount(1, $response->viewData('reviews')->items());
        }
        Http::assertNothingSent();
    }

    public function test_customer_receipt_confirmation_makes_a_star_only_review_available_to_staff(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $order->review()->delete();
        $order->update(['status' => 'ready', 'customer_confirmed_at' => null]);
        $this->actingAs($order->user)->post(route('orders.receipt', $order), ['received' => '1', 'rating' => 4])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/reviews')->assertOk()
            ->assertViewHas('reviewCount', 1)->assertSeeText('4 / 5 · Order rating')
            ->assertSeeText('Star rating only; no written review.');
        $this->assertDatabaseCount('order_reviews', 1);
        Http::assertNothingSent();
    }

    public function test_product_and_star_filters_use_whole_order_ratings_and_leave_other_feedback_visible(): void
    {
        $first = $this->product();
        $second = $this->product('another-reviewed-shirt', 'Another Shirt');
        $low = $this->order($first, 1, 'Honest negative feedback.');
        $this->order($first, 5, 'Good first shirt.');
        $this->order($second, 5, 'Other product feedback.');
        $low->refunds()->create(['requested_by' => $low->user_id, 'reason' => 'Clothing was not suitable.', 'status' => 'succeeded',
            'requested_amount' => $low->total, 'amount' => $low->total]);
        $actor = User::factory()->create(['role' => 'staff']);
        $this->actingAs($actor)->get('/admin/reviews?product_id='.$first->id.'&rating=1')->assertOk()
            ->assertViewHas('reviewCount', 1)->assertSeeText('Honest negative feedback.')
            ->assertDontSeeText('Good first shirt.')->assertDontSeeText('Other product feedback.');
        $this->get('/admin/reviews?product_id='.$second->id)->assertOk()->assertViewHas('reviewCount', 1)
            ->assertSeeText('Other product feedback.')->assertDontSeeText('Honest negative feedback.');
        $first->update(['active' => false, 'name' => 'Renamed Hidden Shirt']);
        $this->get('/admin/reviews?product_id='.$first->id)->assertOk()->assertViewHas('reviewCount', 2)
            ->assertSeeText('Renamed Hidden Shirt')->assertSeeText('Honest negative feedback.');
    }

    public function test_unpaid_unconfirmed_mismatched_or_invalid_ratings_do_not_enter_the_review_workspace(): void
    {
        $product = $this->product();
        $good = $this->order($product, 5, 'Confirmed purchase feedback.');
        $unpaid = $this->order($product, 1, 'UNPAID_FEEDBACK');
        $unpaid->update(['payment_status' => 'partially_paid']);
        $unconfirmed = $this->order($product, 1, 'UNCONFIRMED_FEEDBACK');
        $unconfirmed->update(['customer_confirmed_at' => null]);
        $cancelled = $this->order($product, 1, 'CANCELLED_FEEDBACK');
        $cancelled->update(['status' => 'cancelled']);
        $missingPayment = $this->order($product, 1, 'NO_PAYMENT_REFERENCE');
        $missingPayment->update(['payment_id' => null]);
        $mismatched = $this->order($product, 1, 'MISMATCHED_BUYER');
        $mismatched->review()->update(['user_id' => $good->user_id]);
        $staffReview = $this->order($product, 1, 'STAFF_RATING');
        $staffReview->user->forceFill(['role' => 'staff'])->save();
        $invalid = $this->order($product, 1, 'INVALID_STARS');
        $invalid->review()->update(['rating' => 9]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/reviews')->assertOk()
            ->assertViewHas('reviewCount', 1)->assertSeeText('Confirmed purchase feedback.');
        foreach (['UNPAID_FEEDBACK', 'UNCONFIRMED_FEEDBACK', 'CANCELLED_FEEDBACK', 'NO_PAYMENT_REFERENCE', 'MISMATCHED_BUYER', 'STAFF_RATING', 'INVALID_STARS'] as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    public function test_workspace_escapes_review_text_and_has_no_review_mutation_endpoint(): void
    {
        $order = $this->order($this->product(), 2, '<script>alert("review")</script>');
        $before = $order->review->getAttributes();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/reviews')->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("review")</script>', false);
        $this->patch('/admin/reviews/'.$order->review->id, ['rating' => 5])->assertNotFound();
        $this->delete('/admin/reviews/'.$order->review->id)->assertNotFound();
        $this->assertSame($before, $order->review->fresh()->getAttributes());
    }

    public function test_review_headers_sort_and_pagination_preserve_the_filter(): void
    {
        $product = $this->product();
        foreach (range(1, 21) as $index) {
            $order = $this->order($product, $index === 1 ? 1 : 5, 'Review number '.$index);
            $order->review()->update(['created_at' => now()->subDays(22 - $index)]);
        }
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $response = $this->get('/admin/reviews?product_id='.$product->id)->assertOk()->assertViewHas('reviewCount', 21);
        $this->assertSame(21, $response->viewData('reviews')->first()->id);
        $this->assertCount(20, $response->viewData('reviews')->items());
        $this->assertStringContainsString('product_id='.$product->id, $response->viewData('reviews')->nextPageUrl());
        $this->get('/admin/reviews?product_id='.$product->id.'&page=2')->assertOk()->assertSeeText('Review number 1');
        $this->get('/admin/reviews?sort=rating&direction=asc')->assertOk()
            ->assertViewHas('reviews', fn ($reviews) => $reviews->first()->rating === 1);
        $this->get('/admin/reviews?sort=rating&direction=desc')->assertOk()
            ->assertViewHas('reviews', fn ($reviews) => $reviews->first()->rating === 5);
        foreach (['date', 'product', 'customer', 'review', 'order'] as $column) {
            $this->get('/admin/reviews?sort='.$column.'&direction=asc')->assertOk();
        }
    }

    public function test_invalid_filters_are_rejected_and_empty_results_are_clear(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        foreach (['rating=0', 'rating=6', 'rating[]=1', 'product_id=999999', 'product_id[]=1', 'sort=unsafe', 'direction=sideways'] as $query) {
            $this->getJson('/admin/reviews?'.$query)->assertUnprocessable();
        }
        $this->get('/admin/reviews')->assertOk()->assertSeeText('No customer reviews match these filters.')
            ->assertViewHas('reviewCount', 0)->assertDontSeeText('Average order rating:');
    }
}
