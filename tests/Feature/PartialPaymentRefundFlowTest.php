<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartialPaymentRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_flow', 'shop.paymongo.webhook_secret' => 'flow-webhook']);
        Http::preventStrayRequests();
    }

    private function actor(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function invoice(bool $guest = false, bool $walkIn = false): Order
    {
        $customer = $guest ? null : $this->actor();
        $product = Product::create(['name' => 'Payment flow tee', 'slug' => 'payment-flow-'.Str::uuid(), 'category' => '', 'description' => 'End-to-end payment fixture']);
        $variant = $product->variants()->create(['sku' => 'FLOW-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 60000, 'wholesale_price' => 55000, 'reseller_price' => 50000]);

        return app(OrderFlow::class)->reserve([$variant->id => 2], ['recipient' => 'Payment flow buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'sales_channel' => $walkIn ? 'walk_in' : 'online',
            'payment_plan' => 'partial', 'due_at' => now()->addDays(14)], $customer?->id)->fresh();
    }

    private function cash(Order $order, User $staff, int $amount): PaymentAttempt
    {
        $this->actingAs($staff)->post('/admin/orders/'.$order->id.'/payments/cash', ['amount' => number_format($amount / 100, 2, '.', ''),
            'reason' => 'Cash counted and received at the store counter.', 'payment_received' => '1', 'request_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $order->payments()->where('method', 'cash')->latest('id')->firstOrFail();
    }

    private function checkout(): void
    {
        $count = 0;
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function () use (&$count) {
            $id = 'cs_flow_'.++$count;

            return Http::response(['data' => ['id' => $id, 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/'.$id]]]);
        }]);
    }

    private function online(Order $order, User $actor, int $amount): PaymentAttempt
    {
        $this->actingAs($actor)->post('/orders/'.$order->id.'/payments', ['amount' => number_format($amount / 100, 2, '.', ''), 'request_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $order->payments()->where('method', 'paymongo')->latest('id')->firstOrFail();
    }

    private function webhook(PaymentAttempt $attempt, string $event, string $paymentId)
    {
        $body = json_encode(['data' => ['id' => $event, 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => $attempt->checkout_session_id, 'attributes' => ['reference_number' => $attempt->order->number,
                'payments' => [['id' => $paymentId, 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => $attempt->amount,
                    'paid_at' => now()->subMinutes(2)->timestamp]]]]]]]]);
        $timestamp = (string) time();

        return $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, 'flow-webhook')], $body);
    }

    private function asGuest(): void
    {
        $this->app['auth']->guard()->logout();
        $this->flushSession();
    }

    private function guestRefundUrl(Order $order): string
    {
        return URL::temporarySignedRoute('guest.orders.refunds', now()->addHour(), ['order' => $order->id]);
    }

    private function requestGuestRefund(Order $order): Refund
    {
        $this->asGuest();
        $this->post($this->guestRefundUrl($order), ['reason' => 'Returned clothing checked because the size does not fit.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $order->refunds()->where('status', 'requested')->sole();
    }

    private function approve(Refund $refund, PaymentAttempt $source, User $owner): void
    {
        $this->actingAs($owner)->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => number_format($source->amount / 100, 2, '.', ''),
            'payment_attempt_id' => $source->id, 'decision_note' => 'Returned clothing checked; refund approved against this receipt.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'approved', 'payment_attempt_id' => $source->id,
            'approved_amount' => $source->amount, 'reviewed_by' => $owner->id]);
    }

    private function providerRefund(PaymentAttempt $source, string $reference, string $status = 'succeeded', ?int $timestamp = null): array
    {
        return ['data' => ['id' => $reference, 'type' => 'refund', 'attributes' => ['payment_id' => $source->payment_id, 'currency' => 'PHP',
            'livemode' => false, 'amount' => $source->amount, 'status' => $status, 'updated_at' => $timestamp ?? time()]]];
    }

    private function commerce(Order $order): array
    {
        return ['invoice' => $order->fresh()->getRawOriginal(), 'receipts' => $order->payments()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'stock' => Variant::orderBy('id')->get()->map->getRawOriginal()->all()];
    }

    public function test_guest_mixed_installments_can_be_returned_per_receipt_after_full_payment_and_walk_in_handover(): void
    {
        $order = $this->invoice(true, true);
        $staff = $this->actor('staff');
        $owner = $this->actor('admin');
        $original = $order->only(['number', 'total', 'due_at', 'original_due_at']);
        $deposit = $this->cash($order, $staff, 40000);
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->assertSame(80000, $order->fresh()->outstandingBalance());
        $this->assertFalse($order->fresh()->deliveryConfirmed());
        $this->assertSame(20, Variant::sole()->stock);
        $this->assertSame(2, Variant::sole()->reserved);

        $this->checkout();
        $balance = $this->online($order, $staff, 80000);
        $this->webhook($balance, 'evt_guest_balance', 'pay_guest_balance')->assertOk()->assertJsonPath('outcome', 'paid');
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(120000, $order->paidAmount());
        $this->assertSame(0, $order->outstandingBalance());
        $this->assertTrue($order->deliveryConfirmed());
        $this->assertSame('Delivered', $order->customerDeliveryLabel());
        $this->assertNull($order->customer_confirmed_at);
        $this->assertNotNull($order->walk_in_received_at);
        $this->assertSame(0, $order->review()->count());
        $this->assertSame(18, Variant::sole()->stock);
        $this->assertSame(0, Variant::sole()->reserved);
        $this->assertSame($original['number'], $order->number);
        $this->assertSame($original['total'], $order->total);
        $this->assertTrue($original['due_at']->equalTo($order->due_at));
        $this->assertTrue($original['original_due_at']->equalTo($order->original_due_at));
        $beforeRefund = $this->commerce($order);

        $cashRefund = $this->requestGuestRefund($order);
        $this->assertSame(120000, $cashRefund->requested_amount);
        $this->assertNull($cashRefund->requested_by);
        $this->approve($cashRefund, $deposit, $owner);
        $reason = 'The owner handed the approved cash deposit back to the buyer.';
        $this->post('/admin/refunds/'.$cashRefund->id.'/cash', ['payment_attempt_id' => $deposit->id, 'cash_reason' => $reason])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['id' => $cashRefund->id, 'status' => 'succeeded', 'amount' => 40000, 'cash_recorded_by' => $owner->id,
            'payment_attempt_id' => $deposit->id, 'provider_refund_id' => null, 'cash_reason' => $reason]);
        $this->assertSame(0, Refund::remainingForPayment($order->fresh(), $deposit));
        $this->assertSame(80000, Refund::remainingForOrder($order->fresh()));
        $this->assertSame(1, ActivityLog::where('action', 'refund.cash_recorded')->count());

        $onlineRefund = $this->requestGuestRefund($order);
        $this->assertSame(80000, $onlineRefund->requested_amount);
        $this->approve($onlineRefund, $balance->fresh(), $owner);
        Http::fake(['https://api.paymongo.com/refunds/ref_guest_balance' => Http::response($this->providerRefund($balance->fresh(), 'ref_guest_balance'))]);
        $this->post('/admin/refunds/'.$onlineRefund->id.'/recover', ['provider_refund_id' => 'ref_guest_balance', 'payment_attempt_id' => $balance->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['id' => $onlineRefund->id, 'status' => 'succeeded', 'amount' => 80000,
            'payment_attempt_id' => $balance->id, 'provider_refund_id' => 'ref_guest_balance']);
        $this->assertSame(0, Refund::remainingForOrder($order->fresh()));
        $this->assertSame(0, Refund::remainingForPayment($order->fresh(), $balance->fresh()));
        $this->assertSame($beforeRefund, $this->commerce($order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertDatabaseCount('refunds', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/refunds/ref_guest_balance');

        $this->asGuest();
        $this->post($this->guestRefundUrl($order), ['reason' => 'Trying another request after the entire invoice was returned.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 2);
    }

    public function test_online_installments_require_customer_receipt_and_provider_confirmation_and_cannot_be_counted_or_refunded_twice(): void
    {
        $order = $this->invoice();
        $customer = $order->user;
        $staff = $this->actor('staff');
        $owner = $this->actor('admin');
        $originalDue = $order->getRawOriginal('due_at');
        $this->checkout();
        $deposit = $this->online($order, $customer, 40000);
        $this->webhook($deposit, 'evt_online_deposit', 'pay_online_deposit')->assertOk()->assertJsonPath('outcome', 'partially_paid');
        $this->webhook($deposit, 'evt_online_deposit', 'pay_online_deposit')->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->webhook($deposit, 'evt_online_deposit_replay', 'pay_online_deposit')->assertOk();
        $this->assertSame(40000, $order->fresh()->amount_paid);
        $this->assertSame(20, Variant::sole()->stock);
        $this->assertSame(2, Variant::sole()->reserved);
        $balance = $this->online($order, $customer, 80000);
        $this->webhook($balance, 'evt_online_balance', 'pay_online_balance')->assertOk()->assertJsonPath('outcome', 'paid');
        $this->assertSame($originalDue, $order->fresh()->getRawOriginal('due_at'));
        $this->assertSame(18, Variant::sole()->stock);
        $this->assertSame(0, Variant::sole()->reserved);
        $this->assertSame(0, $order->fresh()->outstandingBalance());
        $this->actingAs($customer)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Request before the customer has received the order.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
        $this->actingAs($staff)->patch('/admin/orders/'.$order->id, ['status' => 'ready', 'reason' => 'The paid clothing has been packed for store pickup.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($customer)->post('/orders/'.$order->id.'/receipt', ['received' => '1', 'rating' => 4,
            'comment' => 'I collected the clothing at the store and checked the sizes.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($order->fresh()->deliveryConfirmed());
        $this->assertSame(1, $order->review()->count());
        $beforeRefund = $this->commerce($order);
        $this->post('/orders/'.$order->id.'/refunds', ['reason' => 'Returned clothing because the chosen size does not fit.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = $order->refunds()->sole();
        $this->actingAs($owner)->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '400.00',
            'payment_attempt_id' => $deposit->id, 'decision_note' => ''])->assertSessionHasErrors('decision_note');
        $this->assertSame('requested', $refund->fresh()->status);
        $this->approve($refund, $deposit->fresh(), $owner);

        $wrongPayment = $this->providerRefund($deposit->fresh(), 'ref_online_deposit', 'pending', time() - 60);
        $wrongPayment['data']['attributes']['payment_id'] = $balance->fresh()->payment_id;
        $succeeded = $this->providerRefund($deposit->fresh(), 'ref_online_deposit');
        Http::fake(['https://api.paymongo.com/refunds/ref_online_deposit' => Http::sequence()
            ->push($wrongPayment)
            ->push($this->providerRefund($deposit->fresh(), 'ref_online_deposit', 'pending', time() - 60))
            ->push($this->providerRefund($deposit->fresh(), 'ref_online_deposit', 'processing', time() - 30))
            ->push(['message' => 'Provider temporarily unavailable'], 500)
            ->push($succeeded)
            ->push($succeeded)]);
        $this->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_online_deposit', 'payment_attempt_id' => $deposit->id])
            ->assertSessionHasErrors('refund');
        $this->assertNull($refund->fresh()->provider_refund_id);
        $this->assertSame($beforeRefund, $this->commerce($order));

        $this->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_online_deposit', 'payment_attempt_id' => $deposit->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->confirmed_at);
        $this->assertSame(80000, Refund::remainingForOrder($order->fresh()));
        $this->actingAs($customer)->get('/orders/'.$order->id)->assertOk()
            ->assertSeeInOrder(['Payments received', '₱1,200.00', 'Confirmed refunds', '₱0.00', 'Remaining paid amount for review', '₱800.00'])
            ->assertSee('₱400.00 is being processed by PayMongo.')
            ->assertDontSee('PayMongo confirmed ₱400.00');
        $this->actingAs($staff)->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame('processing', $refund->fresh()->status);
        $beforeFailure = $refund->fresh()->getRawOriginal();
        $this->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasErrors('refund');
        $this->assertSame($beforeFailure, $refund->fresh()->getRawOriginal());
        $this->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $confirmed = $refund->fresh()->getRawOriginal();
        $this->assertSame('succeeded', $confirmed['status']);
        $this->assertNotNull($confirmed['confirmed_at']);
        $this->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame($confirmed, $refund->fresh()->getRawOriginal());
        $this->assertSame(40000, (int) $order->refunds()->where('status', 'succeeded')->sum('amount'));
        $this->assertSame(0, Refund::remainingForPayment($order->fresh(), $deposit->fresh()));
        $this->assertSame(80000, Refund::remainingForPayment($order->fresh(), $balance->fresh()));
        $this->assertSame($beforeRefund, $this->commerce($order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertDatabaseCount('refunds', 1);
        Http::assertSentCount(6);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_a_real_customer_deposit_does_not_unlock_delivery_or_refunds_while_the_invoice_is_partially_paid(): void
    {
        $order = $this->invoice();
        $deposit = $this->cash($order, $this->actor('staff'), 40000);
        $before = $this->commerce($order);
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Requesting a return while the remaining balance is unpaid.'])->assertSessionHasErrors('refund');
        $this->post('/orders/'.$order->id.'/receipt', ['received' => '1', 'rating' => 4, 'comment' => 'Trying to confirm receipt before paying the full balance.'])->assertSessionHasErrors('receipt');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertSame(40000, $order->fresh()->amount_paid);
        $this->assertSame(80000, $order->fresh()->outstandingBalance());
        $this->assertSame($before, $this->commerce($order));
        Http::assertNothingSent();
    }

    public static function ineligibleGuestInvoices(): array
    {
        return [['pending'], ['partially_paid'], ['missing_handover']];
    }

    #[DataProvider('ineligibleGuestInvoices')]
    public function test_signed_guest_request_cannot_bypass_full_payment_or_recorded_handover(string $state): void
    {
        $order = $this->invoice(true, true);
        if ($state !== 'pending') {
            $this->cash($order, $this->actor('staff'), $state === 'partially_paid' ? 40000 : 120000);
        }
        if ($state === 'missing_handover') {
            $order->refresh()->update(['walk_in_received_at' => null]);
        }
        $before = $this->commerce($order);
        $this->asGuest();
        $this->post($this->guestRefundUrl($order), ['reason' => 'Trying a refund without a settled and handed-over invoice.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame($before, $this->commerce($order));
        Http::assertNothingSent();
    }

    public static function invalidGuestLinks(): array
    {
        return [['unsigned'], ['expired'], ['tampered'], ['registered']];
    }

    #[DataProvider('invalidGuestLinks')]
    public function test_guest_refund_endpoint_rejects_invalid_links_and_registered_customer_invoices(string $case): void
    {
        $order = $this->invoice($case !== 'registered', true);
        $this->cash($order, $this->actor('staff'), 120000);
        $url = $this->guestRefundUrl($order);
        if ($case === 'unsigned') {
            $url = route('guest.orders.refunds', ['order' => $order->id]);
        } elseif ($case === 'expired') {
            $url = URL::temporarySignedRoute('guest.orders.refunds', now()->subMinute(), ['order' => $order->id]);
        } elseif ($case === 'tampered') {
            $foreign = $this->invoice(true, true);
            $url = str_replace('/'.$order->id.'/refunds?', '/'.$foreign->id.'/refunds?', $url);
        }
        $before = $this->commerce($order);
        $this->asGuest();
        $response = $this->post($url, ['reason' => 'Trying to submit through an unauthorized invoice link.']);
        $case === 'registered' ? $response->assertNotFound() : $response->assertForbidden();
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame($before, $this->commerce($order));
        Http::assertNothingSent();
    }

    public static function activeRefundStatuses(): array
    {
        return [['requested'], ['approved'], ['pending'], ['processing']];
    }

    #[DataProvider('activeRefundStatuses')]
    public function test_signed_guest_cannot_create_a_second_request_while_the_first_is_under_review_or_processing(string $status): void
    {
        $order = $this->invoice(true, true);
        $cash = $this->cash($order, $this->actor('staff'), 120000);
        $refund = $this->requestGuestRefund($order);
        if ($status !== 'requested') {
            $refund->update(['status' => $status, 'payment_attempt_id' => $cash->id, 'approved_amount' => $cash->amount,
                'provider_refund_id' => in_array($status, ['pending', 'processing'], true) ? 'ref_guest_active' : null]);
        }
        $before = $this->commerce($order);
        $this->post($this->guestRefundUrl($order), ['reason' => 'Submitting the same return while the first is unresolved.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame($status, $refund->fresh()->status);
        $this->assertSame(1, ActivityLog::where('action', 'refund.requested')->count());
        $this->assertSame($before, $this->commerce($order));
        Http::assertNothingSent();
    }
}
