<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_rejected_registration_shapes_render_a_correctable_form_without_creating_an_account(): void
    {
        $this->from('/register')->post('/register', ['name' => ['wrong'], 'username' => 'correctable_user',
            'email' => 'correctable@example.com', 'password' => 'IsolatedPassword!2026',
            'password_confirmation' => 'IsolatedPassword!2026', 'terms' => '1'])
            ->assertRedirect('/register')->assertSessionHasErrors('name');
        $this->get('/register')->assertOk()->assertSee('correctable_user')->assertSee('correctable@example.com');
        $this->assertDatabaseMissing('users', ['email' => 'correctable@example.com']);
        Http::assertNothingSent();
    }

    public function test_bad_saved_address_inputs_do_not_break_the_form_after_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))->from('/account/addresses/create')
            ->post('/account/addresses', ['recipient' => ['wrong'], 'phone' => ['wrong'], 'label' => 'Home',
                'street_address' => '12 Example Street', 'city' => 'Imus', 'province' => 'Cavite',
                'postal_code' => '4103', 'country' => 'Philippines', 'lat' => ['wrong'], 'lng' => '120.94', 'pin_confirmed' => ['wrong']])
            ->assertRedirect('/account/addresses/create')->assertSessionHasErrors(['recipient', 'phone', 'lat']);
        $this->get('/account/addresses/create')->assertOk()->assertSee('12 Example Street');
        $this->assertDatabaseCount('customer_addresses', 0);
        Http::assertNothingSent();
    }

    public static function invalidProductInputs(): array
    {
        return [
            [['name' => ['wrong'], 'size_chart' => 'wrong']],
            [['size_chart' => [['size' => ['wrong'], 'width' => ['wrong'], 'length' => 27]]]],
        ];
    }

    #[DataProvider('invalidProductInputs')]
    public function test_owner_can_correct_malformed_product_fields_after_validation(array $bad): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->from('/admin/products/create')
            ->post('/admin/products', $bad + ['name' => 'Correctable tee', 'description' => 'Keep this valid description.', 'active' => '1'])
            ->assertRedirect('/admin/products/create')->assertSessionHasErrors();
        $this->get('/admin/products/create')->assertOk()->assertSee('Keep this valid description.');
        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }

    public static function invalidWalkInInputs(): array
    {
        return [['wrong'], [[['variant_id' => ['wrong'], 'quantity' => ['wrong']]]]];
    }

    #[DataProvider('invalidWalkInInputs')]
    public function test_staff_can_reopen_malformed_walk_in_lines_without_saving_a_sale(mixed $lines): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))->from('/admin/walk-in')
            ->post('/admin/walk-in', ['request_key' => 'bad', 'customer_type' => 'guest', 'recipient' => 'Correctable guest',
                'lines' => $lines, 'payment_option' => 'full_cash', 'payment_received' => '1', 'reason' => 'Isolated input validation check.'])
            ->assertRedirect('/admin/walk-in')->assertSessionHasErrors();
        $this->get('/admin/walk-in')->assertOk()->assertSee('Correctable guest');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public static function representativeForms(): array
    {
        return [['/admin/settings', ['opening_hours','pickup_address','pickup_instructions','support_email','support_phone','store_note']],
            ['/admin/settings/faqs/create', ['audience','question','answer','keywords','sort_order']],
            ['/admin/staff/create', ['name','email']]];
    }

    #[DataProvider('representativeForms')]
    public function test_owner_forms_survive_invalid_scalar_old_values(string $url, array $fields): void
    {
        $old = array_fill_keys($fields, ['invalid-shape']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->withSession(['_old_input' => $old])
            ->get($url)->assertOk();
        Http::assertNothingSent();
    }

    public function test_malformed_catalog_filters_render_without_an_error(): void
    {
        $this->get('/?'.http_build_query(['q' => ['wrong'], 'size' => ['wrong'], 'color' => ['wrong']]))
            ->assertOk()->assertSee('Find your next favorite');
        Http::assertNothingSent();
    }

    public function test_malformed_search_in_a_shared_header_does_not_break_sign_in(): void
    {
        $this->get('/login?'.http_build_query(['q' => ['wrong']]))
            ->assertOk()->assertSee('Customer sign in');
        Http::assertNothingSent();
    }

    public function test_owner_product_search_survives_malformed_query_shapes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/products?'.http_build_query(['q' => ['wrong']]))
            ->assertOk()->assertSee('Search products');
        Http::assertNothingSent();
    }
}
