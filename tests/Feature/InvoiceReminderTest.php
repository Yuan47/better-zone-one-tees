<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoiceReminders;
use App\Services\OrderFlow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 16:00:00', 'Asia/Manila'));
        config(['services.brevo.payment_reminders_enabled' => true, 'services.brevo.api_key' => 'mock-brevo-key',
            'services.brevo.from_email' => 'payments@zoneone.example', 'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function invoice(bool $guest = false, array $attributes = []): Order
    {
        $buyer = $guest ? null : User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Reminder test tee', 'slug' => 'reminder-'.Str::uuid(), 'category' => '', 'description' => 'Isolated reminder fixture']);
        $variant = $product->variants()->create(['sku' => 'REMINDER-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
        $due = now()->addDays(3)->setTime(23, 59);
        $order = app(OrderFlow::class)->reserve([$variant->id => 5], ['recipient' => 'Reminder buyer', 'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'shipping' => 0, 'sales_channel' => $guest ? 'walk_in' : 'online', 'payment_plan' => 'partial', 'due_at' => $due], $buyer?->id);
        $order->update($attributes + ['created_at' => now()->subDays(4), 'contact_email' => $guest ? 'guest@example.test' : null]);

        return $order->fresh();
    }

    private function accepted(): void
    {
        $message = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function () use (&$message) {
            return Http::response(['messageId' => '<reminder-'.++$message.'@brevo.example>'], 201);
        }]);
    }

    private function financialState(Order $order): array
    {
        return ['invoice' => $order->fresh()->getRawOriginal(), 'items' => $order->items()->get()->map->getRawOriginal()->all(),
            'receipts' => $order->payments()->get()->map->getRawOriginal()->all(), 'stock' => Variant::orderBy('id')->get(['id', 'stock', 'reserved'])->toArray()];
    }

    public function test_three_day_partial_balance_reminder_uses_brevo_contract_and_leaves_financial_data_unchanged(): void
    {
        $order = $this->invoice(false, ['payment_status' => 'partially_paid', 'amount_paid' => 50000]);
        $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'cash', 'status' => 'paid', 'amount' => 50000,
            'received_at' => now()->subDays(2), 'reason' => 'Fixture cash deposit already received.']);
        $before = $this->financialState($order);
        $this->accepted();
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['sent']);
        $reminder = PaymentReminder::sole();
        $this->assertSame($order->id, $reminder->order_id);
        $this->assertSame(3, $reminder->days_before);
        $this->assertSame(75000, $reminder->amount);
        $this->assertSame($order->user->email, $reminder->recipient);
        $this->assertSame('<reminder-1@brevo.example>', $reminder->provider_message_id);
        $this->assertSame('sent', $reminder->status);
        $this->assertSame(201, $reminder->provider_status);
        $this->assertNotNull($reminder->sent_at);
        $this->assertNotNull($reminder->attempted_at);
        $this->assertTrue($reminder->due_at->equalTo($order->due_at));
        $this->assertNull($reminder->failure_reason);
        $this->assertSame($before, $this->financialState($order));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->header('api-key') === ['mock-brevo-key'] && $request['sender']['email'] === 'payments@zoneone.example'
            && $request['to'][0]['email'] === $order->user->email && $request['headers']['IdempotencyKey'] === $reminder->claim_key
            && str_contains($request['htmlContent'], '₱750.00') && str_contains($request['textContent'], 'PHP 750.00')
            && str_contains($request['textContent'], 'October 19, 2026') && str_contains($request['htmlContent'], route('orders.show', $order)));
    }

    public function test_hourly_runs_deduplicate_each_calendar_day_but_send_separate_three_day_and_one_day_reminders(): void
    {
        $order = $this->invoice();
        $this->accepted();
        $service = app(InvoiceReminders::class);
        $this->assertSame(1, $service->run()['sent']);
        $this->assertSame(0, $service->run()['sent']);
        $this->travelTo(now()->addHours(2));
        $this->assertSame(0, $service->run()['sent']);
        $this->travelTo(Carbon::parse('2026-10-18 00:00:01', 'Asia/Manila'));
        $this->assertSame(1, $service->run()['sent']);
        $this->assertSame(0, $service->run()['sent']);
        $this->assertSame([1, 3], PaymentReminder::orderBy('days_before')->pluck('days_before')->all());
        $this->assertSame(2, PaymentReminder::where('order_id', $order->id)->where('status', 'sent')->count());
        Http::assertSentCount(2);
    }

    public static function manilaCalendarCases(): array
    {
        return [
            ['2026-10-15 15:59:59 UTC', '2026-10-18 00:00:00', 3],
            ['2026-10-15 16:00:00 UTC', '2026-10-19 23:59:59', 3],
            ['2026-10-15 16:00:00 UTC', '2026-10-17 00:00:00', 1],
        ];
    }

    #[DataProvider('manilaCalendarCases')]
    public function test_calendar_offsets_use_manila_dates_instead_of_72_or_24_hour_windows(string $clock, string $due, int $days): void
    {
        $order = $this->invoice(false, ['due_at' => Carbon::parse($due, 'Asia/Manila')]);
        $this->accepted();
        $result = app(InvoiceReminders::class)->run(CarbonImmutable::parse($clock));
        $this->assertSame(1, $result['sent']);
        $this->assertSame($days, PaymentReminder::sole()->days_before);
        $this->assertTrue($order->fresh()->due_at->equalTo(Carbon::parse($due, 'Asia/Manila')));
    }

    public static function ineligibleInvoices(): array
    {
        return [['paid'], ['cancelled'], ['released'], ['no_balance'], ['overpaid_cache'], ['no_due'], ['overdue'], ['today'], ['two_days'], ['four_days']];
    }

    #[DataProvider('ineligibleInvoices')]
    public function test_paid_cancelled_released_and_non_target_deadline_invoices_never_send(string $case): void
    {
        $order = $this->invoice();
        $change = match ($case) {
            'paid' => ['payment_status' => 'paid'], 'cancelled' => ['status' => 'cancelled'], 'released' => ['inventory_released' => true],
            'no_balance' => ['amount_paid' => $order->total], 'overpaid_cache' => ['amount_paid' => $order->total + 1], 'no_due' => ['due_at' => null],
            'overdue' => ['due_at' => now()->subDay()], 'today' => ['due_at' => now()], 'two_days' => ['due_at' => now()->addDays(2)],
            'four_days' => ['due_at' => now()->addDays(4)],
        };
        $order->update($change);
        $before = $this->financialState($order);
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(0, $result['sent']);
        $this->assertDatabaseCount('payment_reminders', 0);
        $this->assertSame($before, $this->financialState($order));
        Http::assertNothingSent();
    }

    public function test_guest_walk_in_uses_its_contact_email_and_a_private_signed_invoice_link_without_creating_an_account(): void
    {
        $order = $this->invoice(true);
        $this->accepted();
        $expectedUrl = URL::temporarySignedRoute('guest.orders.show', $order->due_at->copy()->addDays(7), ['order' => $order->id]);
        $this->assertSame(1, app(InvoiceReminders::class)->run()['sent']);
        $this->assertSame('guest@example.test', PaymentReminder::sole()->recipient);
        $this->assertDatabaseCount('users', 0);
        Http::assertSent(fn ($request) => $request['to'][0]['email'] === 'guest@example.test' && str_contains($request['textContent'], $expectedUrl));
    }

    public function test_registered_customer_uses_only_account_email_instead_of_a_contact_email_override(): void
    {
        $order = $this->invoice(false, ['contact_email' => 'other-person@example.test']);
        $this->accepted();
        app(InvoiceReminders::class)->run();
        $this->assertSame($order->user->email, PaymentReminder::sole()->recipient);
        Http::assertSent(fn ($request) => $request['to'][0]['email'] === $order->user->email && $request['to'][0]['email'] !== 'other-person@example.test');
    }

    public static function invalidRecipients(): array
    {
        return [['guest_missing'], ['guest_invalid'], ['guest_header'], ['guest_non_walk_in'], ['registered_invalid'], ['staff_account']];
    }

    #[DataProvider('invalidRecipients')]
    public function test_missing_invalid_and_non_customer_addresses_are_not_mailed(string $case): void
    {
        $guest = str_starts_with($case, 'guest');
        $order = $this->invoice($guest);
        match ($case) {
            'guest_missing' => $order->update(['contact_email' => null]), 'guest_invalid' => $order->update(['contact_email' => 'not-an-email']),
            'guest_header' => $order->update(['contact_email' => "buyer@example.test\r\nBcc: other@example.test"]),
            'guest_non_walk_in' => $order->update(['sales_channel' => 'online']),
            'registered_invalid' => $order->user->forceFill(['email' => 'invalid'])->save(),
            'staff_account' => $order->user->forceFill(['role' => 'staff'])->save(),
        };
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        $this->assertDatabaseCount('payment_reminders', 0);
        Http::assertNothingSent();
    }

    public static function unavailableConfiguration(): array
    {
        return [['disabled'], ['missing_key'], ['missing_sender'], ['invalid_sender'], ['header_key'], ['header_name']];
    }

    #[DataProvider('unavailableConfiguration')]
    public function test_disabled_or_incomplete_configuration_does_not_send_or_claim(string $case): void
    {
        $this->invoice();
        $change = match ($case) {
            'disabled' => ['services.brevo.payment_reminders_enabled' => false], 'missing_key' => ['services.brevo.api_key' => ''],
            'missing_sender' => ['services.brevo.from_email' => null], 'invalid_sender' => ['services.brevo.from_email' => 'invalid'],
            'header_key' => ['services.brevo.api_key' => "key\r\nInjected: value"], 'header_name' => ['services.brevo.from_name' => "Store\r\nInjected"],
        };
        config($change);
        $this->assertFalse(app(InvoiceReminders::class)->ready());
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        $this->assertDatabaseCount('payment_reminders', 0);
        Http::assertNothingSent();
    }

    public function test_dry_run_can_preview_unconfigured_reminders_without_claiming_or_sending(): void
    {
        $this->invoice();
        config(['services.brevo.payment_reminders_enabled' => false, 'services.brevo.api_key' => null]);
        $result = app(InvoiceReminders::class)->run(dryRun: true);
        $this->assertSame(1, $result['eligible']);
        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['configured']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['sent']);
        $this->artisan('invoices:send-reminders --dry-run')->expectsOutputToContain('1 reminder(s) eligible')->assertSuccessful();
        $this->assertDatabaseCount('payment_reminders', 0);
        Http::assertNothingSent();
    }

    public function test_reentrant_command_claim_does_not_send_a_second_email(): void
    {
        $this->invoice();
        $nested = null;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function () use (&$nested) {
            $nested = app(InvoiceReminders::class)->run();

            return Http::response(['messageId' => '<one-only@brevo.example>'], 201);
        }]);
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $nested['sent']);
        $this->assertSame(1, $nested['skipped']);
        $this->assertDatabaseCount('payment_reminders', 1);
        Http::assertSentCount(1);
    }

    public function test_database_unique_constraint_rejects_an_independent_duplicate_claim(): void
    {
        $order = $this->invoice();
        $values = ['order_id' => $order->id, 'due_at' => $order->due_at, 'days_before' => 3, 'recipient' => $order->user->email, 'amount' => $order->total];
        PaymentReminder::create($values + ['claim_key' => (string) Str::uuid()]);
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        Http::assertNothingSent();
        $this->expectException(QueryException::class);
        PaymentReminder::create($values + ['claim_key' => (string) Str::uuid()]);
    }

    public function test_unknown_transport_outcome_is_saved_and_never_blindly_retried(): void
    {
        $this->invoice();
        $attempts = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('A timeout may have happened after the provider accepted the email.');
        }]);
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(1, $result['unknown']);
        $saved = PaymentReminder::sole();
        $this->assertSame('unknown', $saved->status);
        $this->assertSame('provider_outcome_unknown', $saved->failure_reason);
        $this->assertNull($saved->sent_at);
        $this->assertNull($saved->provider_message_id);
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        $this->assertDatabaseCount('payment_reminders', 1);
        $this->assertSame(1, $attempts);
    }

    public static function providerOutcomes(): array
    {
        return [
            [400, ['message' => 'Invalid sender; do not persist this provider detail.'], 'failed'], [401, [], 'failed'], [403, [], 'failed'],
            [422, [], 'failed'], [429, [], 'failed'], [408, [], 'unknown'], [409, [], 'unknown'], [500, [], 'unknown'], [503, [], 'unknown'],
            [302, [], 'unknown'], [201, [], 'unknown'], [201, ['messageId' => ''], 'unknown'], [201, ['messageId' => 123], 'unknown'],
            [200, ['messageId' => '<unexpected-code@brevo.example>'], 'unknown'],
            [400, ['code' => 'duplicate_parameter', 'message' => 'The idempotency key has already been used.'], 'unknown'],
        ];
    }

    #[DataProvider('providerOutcomes')]
    public function test_provider_rejections_and_uncertain_acceptance_are_recorded_without_automatic_retry(int $status, array $body, string $outcome): void
    {
        $this->invoice();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response($body, $status)]);
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(1, $result[$outcome]);
        $saved = PaymentReminder::sole();
        $this->assertSame($outcome, $saved->status);
        $this->assertSame($status, $saved->provider_status);
        $this->assertNull($saved->sent_at);
        $this->assertNull($saved->provider_message_id);
        $this->assertNotNull($saved->failure_reason);
        $this->assertStringNotContainsString('Invalid sender', $saved->toJson());
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        Http::assertSentCount(1);
    }

    public function test_a_repeated_provider_message_id_cannot_confirm_two_different_reminders(): void
    {
        $this->invoice();
        $this->invoice();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<duplicate@brevo.example>'], 201)]);
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['unknown']);
        $this->assertSame(1, PaymentReminder::where('provider_message_id', '<duplicate@brevo.example>')->count());
        $this->assertSame(1, PaymentReminder::where('status', 'unknown')->whereNull('sent_at')->count());
        Http::assertSentCount(2);
    }

    public static function changedBeforeSend(): array
    {
        return [['paid'], ['cancelled'], ['deadline'], ['recipient'], ['disabled']];
    }

    #[DataProvider('changedBeforeSend')]
    public function test_current_invoice_and_recipient_are_rechecked_after_claim_before_any_http(string $case): void
    {
        $order = $this->invoice();
        PaymentReminder::created(function () use ($case, $order) {
            match ($case) {
                'paid' => $order->update(['payment_status' => 'paid', 'amount_paid' => $order->total]),
                'cancelled' => $order->update(['status' => 'cancelled']), 'deadline' => $order->update(['due_at' => now()->addDays(8)]),
                'recipient' => $order->user->forceFill(['email' => 'invalid'])->save(),
                'disabled' => config(['services.brevo.payment_reminders_enabled' => false]),
            };
        });
        $result = app(InvoiceReminders::class)->run();
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $saved = PaymentReminder::sole();
        $this->assertSame('skipped', $saved->status);
        $this->assertNull($saved->sent_at);
        $this->assertNull($saved->attempted_at);
        Http::assertNothingSent();
    }

    public function test_a_payment_received_between_selection_and_send_reduces_the_reminder_to_the_fresh_balance(): void
    {
        $order = $this->invoice();
        PaymentReminder::created(fn () => $order->update(['payment_status' => 'partially_paid', 'amount_paid' => 100000]));
        $this->accepted();
        $this->assertSame(1, app(InvoiceReminders::class)->run()['sent']);
        $this->assertSame(25000, PaymentReminder::sole()->amount);
        Http::assertSent(fn ($request) => str_contains($request['textContent'], 'PHP 250.00') && ! str_contains($request['textContent'], 'PHP 1,250.00'));
    }

    public function test_a_run_crossing_manila_midnight_rechecks_the_calendar_day_before_sending(): void
    {
        $this->invoice();
        PaymentReminder::created(fn () => $this->travelTo(Carbon::parse('2026-10-17 00:00:01', 'Asia/Manila')));
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        $this->assertSame('skipped', PaymentReminder::sole()->status);
        Http::assertNothingSent();
    }

    public function test_a_logged_deadline_extension_can_get_a_new_reminder_without_changing_the_original_deadline(): void
    {
        $order = $this->invoice();
        $original = $order->original_due_at;
        $this->accepted();
        $this->assertSame(1, app(InvoiceReminders::class)->run()['sent']);
        $firstDue = PaymentReminder::sole()->due_at->format('Y-m-d H:i:s');
        $order->update(['due_at' => now()->addDays(7)]);
        $this->travelTo(Carbon::parse('2026-10-18 16:00:00', 'Asia/Manila'));
        $this->assertSame(0, app(InvoiceReminders::class)->run()['sent']);
        $this->travelTo(Carbon::parse('2026-10-20 16:00:00', 'Asia/Manila'));
        $this->assertSame(1, app(InvoiceReminders::class)->run()['sent']);
        $this->assertDatabaseCount('payment_reminders', 2);
        $this->assertSame($firstDue, PaymentReminder::oldest('id')->first()->due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($order->fresh()->original_due_at->equalTo($original));
        Http::assertSentCount(2);
    }

    public function test_email_content_escapes_customer_input_and_does_not_invent_late_fees_or_cancellation(): void
    {
        $order = $this->invoice();
        $order->user->forceFill(['name' => '<script>alert("customer")</script>'])->save();
        $this->accepted();
        app(InvoiceReminders::class)->run();
        Http::assertSent(fn ($request) => str_contains($request['htmlContent'], '&lt;script&gt;') && ! str_contains($request['htmlContent'], '<script>')
            && ! str_contains(strtolower($request['htmlContent']), 'late fee') && ! str_contains(strtolower($request['htmlContent']), 'automatically cancelled'));
    }

    public function test_command_reports_disabled_success_and_uncertain_failure_without_exposing_provider_details(): void
    {
        $this->invoice();
        config(['services.brevo.payment_reminders_enabled' => false]);
        $this->artisan('invoices:send-reminders')->expectsOutputToContain('No email sent')->assertSuccessful();
        config(['services.brevo.payment_reminders_enabled' => true]);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Private provider diagnostic'], 503)]);
        $this->artisan('invoices:send-reminders')->expectsOutputToContain('1 uncertain')->expectsOutputToContain('will not automatically resend')->assertFailed();
        $this->assertSame('unknown', PaymentReminder::sole()->status);
        Http::assertSentCount(1);
    }
}
