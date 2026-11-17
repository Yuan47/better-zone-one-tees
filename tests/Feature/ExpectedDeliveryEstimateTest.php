<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChatbotFaq;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\DeliveryEstimates;
use App\Services\InvoicePayments;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpectedDeliveryEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.groq.key' => null]);
        Http::preventStrayRequests();
    }

    private function setDays(string $days): void
    {
        app(StoreKnowledge::class)->saveInfo(['expected_delivery_days' => $days]);
    }

    private function variant(): Variant
    {
        $suffix = (string) Str::uuid();
        $product = Product::create(['name' => 'Delivery Estimate Tee', 'slug' => 'delivery-estimate-'.$suffix,
            'category' => '', 'description' => 'Isolated delivery estimate fixture']);

        return $product->variants()->create(['sku' => 'ESTIMATE-'.$suffix, 'size' => 'M', 'color' => 'White',
            'stock' => 20, 'retail_price' => 16000, 'wholesale_price' => 15000, 'reseller_price' => 14000]);
    }

    private function quote(): array
    {
        return ['quotationId' => 'quote_delivery_estimate', 'priceBreakdown' => ['currency' => 'PHP', 'total' => '44.00'],
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']]];
    }

    private function order(User $customer, array $details = []): Order
    {
        return app(OrderFlow::class)->reserve([$this->variant()->id => 2], $details + [
            'recipient' => 'Estimate Buyer', 'phone' => '09123456789', 'address' => '12 Example Street, Imus, Cavite, 4103, Philippines',
            'delivery_method' => 'lalamove', 'lat' => '14.425', 'lng' => '120.94', 'shipping' => 4400,
            'sales_channel' => 'online', 'delivery_quote' => $this->quote(),
        ], $customer->id);
    }

    public function test_default_delivery_estimate_is_available_without_inventing_other_store_information(): void
    {
        DB::table('shop_settings')->where('key', 'store_info')->delete();
        $info = app(StoreKnowledge::class)->info();
        $this->assertSame('1-2', $info['expected_delivery_days']);
        $this->assertSame('1-2', app(DeliveryEstimates::class)->current());
        $this->assertSame('', $info['opening_hours']);
        $this->assertSame('', $info['support_email']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.settings'))->assertOk()
            ->assertSee('name="expected_delivery_days"', false)->assertSee('value="1-2"', false)
            ->assertSee('ascending range')->assertSee('not a guaranteed courier arrival');
        Http::assertNothingSent();
    }

    public static function validDays(): array
    {
        return [['0'], ['1'], ['3'], ['999'], ['0-1'], ['1-2'], ['2-2'], ['7-14'], ['998-999']];
    }

    #[DataProvider('validDays')]
    public function test_owner_can_save_a_number_or_an_ascending_delivery_range(string $days): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->post(route('admin.settings.save'), ['expected_delivery_days' => $days])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($days, app(DeliveryEstimates::class)->current());
        if ($days !== '1-2') {
            $audit = ActivityLog::where('action', 'settings.updated')->latest('id')->firstOrFail();
            $this->assertSame($owner->id, $audit->actor_id);
            $this->assertContains('expected_delivery_days', $audit->metadata['fields']);
        }
        Http::assertNothingSent();
    }

    public static function invalidDays(): array
    {
        return [[''], [null], ['-1'], ['2-1'], ['1.5'], ['1-2-3'], ['1--2'], ['1 -2'], ['1–2'], ['1000'], ['1-1000'], ['next day'], [['1-2']]];
    }

    #[DataProvider('invalidDays')]
    public function test_invalid_delivery_settings_are_rejected_without_replacing_the_current_value(mixed $days): void
    {
        $this->setDays('3-5');
        $before = DB::table('shop_settings')->where('key', 'store_info')->value('value');
        $audits = ActivityLog::where('action', 'settings.updated')->count();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.settings.save'), ['expected_delivery_days' => $days])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_delivery_days');
        $this->assertSame($before, DB::table('shop_settings')->where('key', 'store_info')->value('value'));
        $this->assertSame('3-5', app(DeliveryEstimates::class)->current());
        $this->assertSame($audits, ActivityLog::where('action', 'settings.updated')->count());
    }

    public function test_older_store_information_forms_preserve_the_previously_saved_delivery_days(): void
    {
        $this->setDays('7-14');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.settings.save'), ['opening_hours' => 'Monday to Saturday'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('7-14', app(DeliveryEstimates::class)->current());
        $this->assertSame('Monday to Saturday', app(StoreKnowledge::class)->info()['opening_hours']);
        $this->post(route('admin.settings.save'), ['opening_hours' => 'Monday to Saturday'])
            ->assertSessionHas('notice', 'No store information changes to save.');
    }

    public function test_guests_customers_and_staff_cannot_change_the_owner_delivery_setting(): void
    {
        $this->setDays('3-5');
        $this->post(route('admin.settings.save'), ['expected_delivery_days' => '9'])->assertRedirect(route('admin.login'));
        foreach (['customer', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.settings.save'), ['expected_delivery_days' => '9'])->assertForbidden();
        }
        $this->assertSame('3-5', app(DeliveryEstimates::class)->current());
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.updated']);
    }

    public function test_malformed_legacy_delivery_settings_fall_back_to_the_default(): void
    {
        foreach (['not a range', '9-2', 123, ['1-2'], null] as $value) {
            DB::table('shop_settings')->updateOrInsert(['key' => 'store_info'], ['value' => json_encode([
                'expected_delivery_days' => $value, 'store_note' => 'Keep this approved note.',
            ], JSON_THROW_ON_ERROR)]);
            $this->assertSame('1-2', app(DeliveryEstimates::class)->current());
            $this->assertSame('Keep this approved note.', app(StoreKnowledge::class)->info()['store_note']);
        }
    }

    public function test_online_delivery_orders_snapshot_the_server_estimate_without_mutating_provider_data(): void
    {
        $this->setDays('3-5');
        $customer = User::factory()->create();
        $quote = $this->quote();
        $order = $this->order($customer, ['expected_delivery_days' => '999', 'delivery_quote' => $quote]);
        $this->assertSame('3-5', $order->fresh()->expected_delivery_days);
        $this->assertSame($quote, $order->fresh()->delivery_quote);
        $this->assertSame(4400, $order->shipping);
        $this->assertSame($order->subtotal + 4400, $order->total);
        $this->setDays('7-14');
        $this->assertSame('3-5', $order->fresh()->expected_delivery_days);
        $this->assertSame('7-14', $this->order($customer)->fresh()->expected_delivery_days);
        $this->assertSame($quote, $order->fresh()->delivery_quote);
        Http::assertNothingSent();
    }

    public function test_http_checkout_ignores_a_customer_injected_estimate_and_keeps_a_pending_invoice(): void
    {
        $this->setDays('3-5');
        $variant = $this->variant();
        $quote = $this->quote();
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('create')->once()->andReturn(['checkout_session_id' => 'cs_estimate_test',
                'checkout_url' => 'https://checkout.paymongo.com/estimate-test']);
        });
        $address = '12 Example Street, Imus, Cavite, 4103, Philippines';
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$variant->id => 2], 'delivery_quote' => [
            'quote' => $quote, 'address' => $address, 'lat' => '14.425', 'lng' => '120.94',
        ]])->post('/checkout', ['recipient' => 'Estimate Buyer', 'phone' => '09123456789', 'delivery_method' => 'lalamove',
            'terms' => '1', 'street_address' => '12 Example Street', 'city' => 'Imus', 'province' => 'Cavite',
            'postal_code' => '4103', 'country' => 'Philippines', 'lat' => '14.425', 'lng' => '120.94',
            'pin_confirmed' => '1', 'expected_delivery_days' => '999',
        ])->assertRedirect('https://checkout.paymongo.com/estimate-test');
        $order = Order::sole();
        $this->assertSame('3-5', $order->expected_delivery_days);
        $this->assertSame($quote, $order->delivery_quote);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertFalse($order->inventory_committed);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
    }

    public function test_pickup_and_walk_in_orders_do_not_receive_a_delivery_estimate_snapshot(): void
    {
        $customer = User::factory()->create();
        foreach ([['delivery_method' => 'pickup', 'shipping' => 0], ['sales_channel' => 'walk_in']] as $details) {
            $order = $this->order($customer, $details + ['expected_delivery_days' => '99']);
            $this->assertNull($order->fresh()->expected_delivery_days);
            $this->assertNull(app(DeliveryEstimates::class)->forOrder($order->fresh()));
        }
    }

    public function test_checkout_displays_current_guidance_inside_the_delivery_fields_before_payment(): void
    {
        $this->setDays('3-5');
        $variant = $this->variant();
        $this->mock(PayMongo::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true));
        $this->mock(Lalamove::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true));
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$variant->id => 1]])->get('/checkout')
            ->assertOk()->assertSeeInOrder(['id="delivery-fields"', 'data-delivery-estimate', '3-5 days after confirmed full payment.'], false)
            ->assertSee('The estimate starts only after full payment is confirmed.')
            ->assertSee('Timing may vary with packing, courier availability and location.');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_a_checkout_return_does_not_start_the_estimate_or_confirm_payment(): void
    {
        $this->setDays('3-5');
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $this->actingAs($customer)->get('/orders/'.$order->id.'?payment=success')->assertOk()
            ->assertSee('Expected delivery: 3-5 days after confirmed full payment.')
            ->assertSee('The estimate starts only after full payment is confirmed.');
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertFalse($order->fresh()->inventory_committed);
        Http::assertNothingSent();
    }

    public function test_verified_full_payment_keeps_the_saved_estimate_and_provider_payment_date(): void
    {
        $this->setDays('3-5');
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $attempt = $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo',
            'status' => 'pending', 'amount' => $order->total]);
        $paidAt = now()->subMinute()->startOfSecond();
        $payment = ['id' => 'pay_estimate_'.$attempt->id, 'attributes' => ['status' => 'paid', 'amount' => $attempt->amount,
            'currency' => 'PHP', 'livemode' => false, 'paid_at' => $paidAt->timestamp]];
        $this->assertSame('paid', app(InvoicePayments::class)->receiveProvider($attempt, $payment));
        $this->setDays('7-14');
        $this->actingAs($customer)->get('/orders/'.$order->id)->assertOk()
            ->assertSee('Expected delivery: 3-5 days after confirmed full payment.')
            ->assertDontSee('The estimate starts only after full payment is confirmed.')
            ->assertDontSee('Expected delivery: 7-14');
        $this->assertSame($paidAt->timestamp, $order->fresh()->paid_at->timestamp);
        $this->assertSame('3-5', $order->fresh()->expected_delivery_days);
        $this->assertTrue($order->fresh()->inventory_committed);
        $variant = $order->items()->firstOrFail()->variant;
        $this->assertSame(18, $variant->stock);
        $this->assertSame(0, $variant->reserved);
        $this->assertSame('paid', app(InvoicePayments::class)->receiveProvider($attempt, $payment));
        $this->assertSame(18, $variant->fresh()->stock);
        Http::assertNothingSent();
    }

    public function test_legacy_orders_show_labeled_current_guidance_without_backfilling_the_invoice(): void
    {
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $order->update(['expected_delivery_days' => null]);
        $this->setDays('4-6');
        $this->actingAs($customer)->get('/orders/'.$order->id)->assertOk()
            ->assertSee('Current store delivery estimate: 4-6 days after confirmed full payment.');
        $this->assertNull($order->fresh()->expected_delivery_days);
        $this->setDays('1');
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Current store delivery estimate: 1 day after confirmed full payment.');
        $this->assertNull($order->fresh()->expected_delivery_days);
    }

    public function test_delivery_estimate_is_not_shown_for_handover_closed_or_customer_delivered_orders(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        foreach ([['delivery_method' => 'pickup'], ['sales_channel' => 'walk_in'], ['status' => 'cancelled'],
            ['inventory_released' => true], ['status' => 'completed', 'customer_confirmed_at' => now()]] as $changes) {
            $order = $this->order($customer);
            $order->update($changes);
            $this->get('/orders/'.$order->id)->assertOk()->assertDontSee('data-delivery-estimate', false);
            $this->assertNull(app(DeliveryEstimates::class)->forOrder($order->fresh()));
        }
    }

    public function test_public_chat_uses_current_delivery_days_and_never_calls_ai_for_this_store_fact(): void
    {
        config(['shop.groq.key' => 'test-no-network', 'shop.groq.model' => 'test-model']);
        foreach (['When will my order arrive?', 'What is the expected delivery after payment confirmed?', 'How many days does delivery take?'] as $question) {
            $this->setDays('3-5');
            $this->postJson(route('chat'), ['message' => $question, 'side' => 'customer'])->assertOk()
                ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '3-5 days after confirmed full payment')
                    && str_contains($reply, 'not a guaranteed courier arrival time'));
        }
        $this->setDays('7-14');
        $this->postJson(route('chat'), ['message' => 'When will my order arrive?', 'side' => 'customer'])->assertOk()
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '7-14 days after confirmed full payment') && ! str_contains($reply, '3-5 days'));
        Http::assertNothingSent();
    }

    public function test_eta_help_does_not_override_refund_fee_or_private_order_questions(): void
    {
        $this->setDays('3-5');
        foreach (['What is the expected delivery fee?', 'Can I refund the expected delivery fee?'] as $question) {
            $answer = 'Only this approved fee or refund guidance applies.';
            ChatbotFaq::create(['question' => $question, 'answer' => $answer, 'active' => true, 'audience' => 'customer']);
            $this->postJson(route('chat'), ['message' => $question, 'side' => 'customer'])->assertOk()
                ->assertJsonPath('reply', $answer)->assertDontSee('3-5 days');
        }
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->actingAs(User::factory()->create())->postJson(route('chat'), [
            'message' => 'Track order '.$order->number.' and expected delivery', 'side' => 'customer',
        ])->assertOk()->assertSee('Sign in and share your full order number')->assertDontSee($order->number)->assertDontSee('3-5 days');
        $this->postJson(route('chat'), ['message' => 'How does the owner change the expected delivery days?', 'side' => 'customer'])
            ->assertOk()->assertJsonPath('reply', StoreKnowledge::CUSTOMER_PRIVACY_HELP);
        Http::assertNothingSent();
    }

    public function test_additive_migration_is_repeatable_and_preserves_legacy_invoices_and_owner_faq_edits(): void
    {
        $this->setDays('7-14');
        $order = $this->order(User::factory()->create());
        $order->update(['expected_delivery_days' => null]);
        $faq = ChatbotFaq::where('question', 'Where can I see the expected delivery days after payment?')->sole();
        $faq->update(['answer' => 'Owner-approved custom delivery guide.', 'active' => false,
            'audience' => 'owner', 'keywords' => 'custom guide', 'sort_order' => 987]);
        $beforeFaqs = ChatbotFaq::orderBy('id')->get()->toArray();
        $beforeOrder = $order->fresh()->toArray();
        // Recreate the pre-update schema in this isolated database, with an existing invoice.
        Schema::table('orders', fn ($table) => $table->dropColumn('expected_delivery_days'));
        $migration = require database_path('migrations/2026_11_17_000033_add_expected_delivery_estimates.php');
        $migration->up();
        $migration->up();
        $migration->down();
        (require database_path('migrations/2026_10_11_000004_create_chatbot_faqs_and_store_info.php'))->up();
        $this->assertSame($beforeFaqs, ChatbotFaq::orderBy('id')->get()->toArray());
        $this->assertSame($beforeOrder, $order->fresh()->toArray());
        $this->assertNull($order->fresh()->expected_delivery_days);
        $this->assertSame('7-14', app(DeliveryEstimates::class)->current());
    }
}
