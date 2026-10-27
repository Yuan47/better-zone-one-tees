<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReminderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 16:00:00', 'Asia/Manila'));
        config(['services.brevo.payment_reminders_enabled' => false, 'services.brevo.api_key' => 'brevo-private-key-never-display',
            'services.brevo.from_email' => 'payments@example.test', 'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function actor(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function invoice(bool $guest = false): Order
    {
        return Order::create(['number' => 'ZOT-HISTORY-'.Str::random(8), 'user_id' => $guest ? null : $this->actor('customer')->id,
            'quantity' => 5, 'tier' => 'retail', 'subtotal' => 125000, 'merchandise_base' => 125000, 'total' => 125000, 'discount' => 0, 'shipping' => 0,
            'payment_status' => 'partially_paid', 'payment_plan' => 'partial', 'amount_paid' => 50000, 'status' => 'awaiting_payment',
            'recipient' => 'History invoice buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup',
            'sales_channel' => $guest ? 'walk_in' : 'online', 'due_at' => now()->addDays(3), 'original_due_at' => now()->addDays(3)])->fresh();
    }

    private function reminder(Order $order, string $status = 'sent', int $offset = 0, array $attributes = []): PaymentReminder
    {
        return PaymentReminder::create($attributes + ['order_id' => $order->id, 'due_at' => $order->due_at->copy()->subDays($offset),
            'days_before' => 3, 'claim_key' => (string) Str::uuid(), 'recipient' => 'private-reminder-recipient@example.test', 'amount' => 75000,
            'status' => $status, 'provider_message_id' => $status === 'sent' ? '<private-history-'.Str::random(8).'@brevo.example>' : null,
            'provider_status' => $status === 'sent' ? 201 : null, 'failure_reason' => $status === 'unknown' ? 'provider_outcome_unknown' : null,
            'attempted_at' => in_array($status, ['sent', 'unknown', 'failed'], true) ? now()->subMinutes(2) : null,
            'sent_at' => $status === 'sent' ? now()->subMinute() : null]);
    }

    public function test_settings_and_reminder_receipt_details_are_owner_only(): void
    {
        $order = $this->invoice();
        $reminder = $this->reminder($order);
        $this->get('/admin/settings')->assertRedirect('/admin/login');
        foreach (['staff', 'customer'] as $role) {
            $this->actingAs($this->actor($role))->get('/admin/settings')->assertForbidden()->assertDontSee($reminder->recipient)->assertDontSee($reminder->provider_message_id);
        }
        $this->actingAs($this->actor('admin'))->get('/admin/settings')->assertOk()->assertSee('Email payment reminders')
            ->assertSee($reminder->recipient)->assertSee($reminder->provider_message_id)->assertDontSee('brevo-private-key-never-display');
        Http::assertNothingSent();
    }

    public static function configurationStates(): array
    {
        return [[false, true, 'Disabled', 'Configured'], [true, false, 'Waiting for setup', 'Not configured'], [true, true, 'Enabled', 'Configured']];
    }

    #[DataProvider('configurationStates')]
    public function test_read_only_configuration_status_never_exposes_or_accepts_email_credentials(bool $enabled, bool $configured, string $sending, string $connection): void
    {
        config(['services.brevo.payment_reminders_enabled' => $enabled, 'services.brevo.api_key' => $configured ? 'brevo-private-key-never-display' : null]);
        $this->actingAs($this->actor('admin'))->get('/admin/settings')->assertOk()
            ->assertSee('<span class="badge">'.$sending.'</span>', false)->assertSee('<span class="badge">'.$connection.'</span>', false)
            ->assertSee('3 days and 1 day before the deadline')->assertSee('it does not confirm inbox delivery')
            ->assertSee('Unknown or interrupted outcomes are never blindly retried')->assertDontSee('brevo-private-key-never-display')
            ->assertDontSee('name="BREVO_API_KEY"', false)->assertDontSee('name="BREVO_PAYMENT_REMINDERS_ENABLED"', false)
            ->assertSee('No reminder attempts have been recorded yet.');
        $this->assertDatabaseCount('payment_reminders', 0);
        Http::assertNothingSent();
    }

    public function test_owner_history_distinguishes_provider_acceptance_uncertainty_failure_and_unsent_changes_without_mutating_records(): void
    {
        $order = $this->invoice();
        foreach (['sent', 'unknown', 'failed', 'claimed', 'skipped'] as $offset => $status) {
            $this->reminder($order, $status, $offset);
        }
        $beforeOrder = $order->getRawOriginal();
        $beforeReminders = PaymentReminder::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->actor('admin'))->get('/admin/settings')->assertOk()->assertSee('3 attempts')
            ->assertSee('Accepted by Brevo')->assertSee('Email outcome unknown')->assertSee('Email send failed')->assertSee('Awaiting send outcome')
            ->assertSee('Invoice changed')->assertSee('No email was sent for this attempt.')->assertSee('₱750.00')
            ->assertDontSee('provider_outcome_unknown');
        $this->assertSame($beforeOrder, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeReminders, PaymentReminder::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_global_history_is_bounded_to_latest_twelve_attempts(): void
    {
        $order = $this->invoice();
        $first = null;
        $last = null;
        foreach (range(0, 14) as $offset) {
            $saved = $this->reminder($order, 'sent', $offset);
            $first ??= $saved;
            $last = $saved;
        }
        $response = $this->actingAs($this->actor('admin'))->get('/admin/settings')->assertOk()
            ->assertSee('Latest 12 attempts')->assertSee($last->provider_message_id)->assertDontSee($first->provider_message_id);
        $this->assertSame(12, substr_count($response->getContent(), 'class="panel reminder-card"'));
        $this->assertDatabaseCount('payment_reminders', 15);
        Http::assertNothingSent();
    }

    public function test_invoice_history_contains_only_that_invoice_and_remains_hidden_from_customer_and_staff(): void
    {
        $order = $this->invoice();
        $own = $this->reminder($order);
        $other = $this->reminder($this->invoice(), 'sent', attributes: ['recipient' => 'another-invoice-secret@example.test']);
        $this->actingAs($this->actor('admin'))->get('/orders/'.$order->id)->assertOk()->assertSee('Email reminder history')
            ->assertSee($own->recipient)->assertSee($own->provider_message_id)->assertDontSee($other->recipient)->assertDontSee($other->provider_message_id);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains(strtolower($query->sql), 'payment_reminders')) {
                $queries[] = $query->sql;
            }
        });
        foreach ([$order->user, $this->actor('staff')] as $actor) {
            $this->actingAs($actor)->get('/orders/'.$order->id)->assertOk()->assertDontSee('Email reminder history')
                ->assertDontSee($own->recipient)->assertDontSee($own->provider_message_id)->assertDontSee('Email service reference');
        }
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_private_guest_invoice_never_contains_owner_reminder_receipt_details(): void
    {
        $order = $this->invoice(true);
        $reminder = $this->reminder($order);
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addDay(), ['order' => $order->id]);
        $this->get($url)->assertOk()->assertDontSee('Email reminder history')->assertDontSee($reminder->recipient)->assertDontSee($reminder->provider_message_id);
        Http::assertNothingSent();
    }

    public function test_reminder_details_escape_stored_content_and_walk_in_completion_uses_store_language(): void
    {
        $order = $this->invoice(true);
        $order->update(['status' => 'completed', 'payment_status' => 'paid', 'amount_paid' => $order->total, 'paid_at' => now(), 'walk_in_received_at' => now()]);
        $this->reminder($order, attributes: ['recipient' => '<script>recipient-history</script>', 'provider_message_id' => '<script>provider-history</script>']);
        $this->actingAs($this->actor('admin'))->get('/orders/'.$order->id)->assertOk()
            ->assertSee('&lt;script&gt;recipient-history&lt;/script&gt;', false)->assertDontSee('<script>recipient-history</script>', false)
            ->assertSee('&lt;script&gt;provider-history&lt;/script&gt;', false)->assertDontSee('<script>provider-history</script>', false)
            ->assertSee('The walk-in sale is complete.')->assertDontSee('No customer receipt or review was invented.');
        Http::assertNothingSent();
    }
}
