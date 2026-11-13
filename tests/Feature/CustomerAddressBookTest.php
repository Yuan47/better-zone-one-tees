<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CustomerAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'customer', ...$attributes]);
    }

    private function address(array $overrides = []): array
    {
        return array_replace([
            'label' => 'Home', 'recipient' => 'Test Customer', 'phone' => '09123456789',
            'street_address' => '12 Sample Street, Barangay Uno', 'city' => 'Imus', 'province' => 'Cavite',
            'postal_code' => '4103', 'country' => 'Philippines', 'lat' => '14.425143', 'lng' => '120.940319',
            'pin_confirmed' => '1',
        ], $overrides);
    }

    public function test_customer_can_save_edit_choose_default_and_remove_addresses(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->post('/account/addresses', $this->address())->assertRedirect('/account/addresses');
        $home = CustomerAddress::sole();
        $this->assertSame($user->id, $home->user_id);
        $this->assertTrue($home->is_default);
        $this->post('/account/addresses', $this->address(['label' => 'Work', 'street_address' => '20 Office Street']))->assertRedirect('/account/addresses');
        $work = CustomerAddress::latest('id')->first();
        $this->assertFalse($work->is_default);
        $this->patch('/account/addresses/'.$work->id.'/default')->assertRedirect('/account/addresses');
        $this->assertFalse($home->fresh()->is_default);
        $this->assertTrue($work->fresh()->is_default);
        $this->patch('/account/addresses/'.$work->id.'/default')->assertRedirect('/account/addresses');
        $this->assertTrue($work->fresh()->is_default);
        $this->assertSame(1, CustomerAddress::where('user_id', $user->id)->where('is_default', true)->count());
        $this->patch('/account/addresses/'.$work->id, $this->address(['label' => 'Office', 'street_address' => '21 Office Street']))->assertRedirect('/account/addresses');
        $this->assertSame('21 Office Street', $work->fresh()->street_address);
        $this->assertTrue($work->fresh()->is_default);
        $this->delete('/account/addresses/'.$work->id)->assertRedirect('/account/addresses');
        $this->assertTrue($home->fresh()->is_default);
        $this->get('/account/addresses')->assertOk()->assertSee('My addresses')->assertSee('12 Sample Street');
    }

    public function test_delivery_pin_confirmation_complete_address_and_local_phone_are_required(): void
    {
        $this->actingAs($this->customer());
        foreach ([['lat' => null], ['lng' => null], ['pin_confirmed' => null], ['phone' => '+639123456789'],
            ['phone' => '0912345678'], ['phone' => '091234567890'], ['city' => null], ['country' => 'Singapore']] as $invalid) {
            $this->post('/account/addresses', $this->address($invalid))->assertSessionHasErrors(array_keys($invalid));
        }
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_other_accounts_cannot_read_edit_delete_or_choose_an_address(): void
    {
        $owner = $this->customer();
        $address = app(CustomerAddresses::class)->save($owner, array_diff_key($this->address(), ['pin_confirmed' => true]));
        $other = $this->customer();
        $this->actingAs($other)->get('/account/addresses')->assertOk()->assertDontSee('12 Sample Street');
        $this->get('/account/addresses/'.$address->id.'/edit')->assertNotFound();
        $this->patch('/account/addresses/'.$address->id, $this->address())->assertNotFound();
        $this->patch('/account/addresses/'.$address->id.'/default')->assertNotFound();
        $this->delete('/account/addresses/'.$address->id)->assertNotFound();
        $this->postJson('/checkout/quote', ['address_id' => $address->id])->assertNotFound();
        $this->assertSame($owner->id, $address->fresh()->user_id);
    }

    public function test_staff_and_owner_cannot_use_the_customer_address_book(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/account/addresses')->assertForbidden();
            $this->post('/account/addresses', $this->address())->assertForbidden();
        }
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_selected_address_uses_owned_saved_values_instead_of_client_overrides(): void
    {
        $user = $this->customer();
        $saved = app(CustomerAddresses::class)->save($user, array_diff_key($this->address(), ['pin_confirmed' => true]));
        $request = Request::create('/checkout/quote', 'POST', ['address_id' => $saved->id, 'street_address' => 'Injected street', 'phone' => '000']);
        $request->setUserResolver(fn () => $user);
        app(CustomerAddresses::class)->applySelected($request);
        $this->assertSame('12 Sample Street, Barangay Uno', $request->input('street_address'));
        $this->assertSame('09123456789', $request->input('phone'));
        $this->assertEqualsWithDelta(14.425143, (float) $request->input('lat'), 0.000001);
    }

    public function test_address_edit_clears_the_previous_delivery_quotation(): void
    {
        $user = $this->customer();
        $saved = app(CustomerAddresses::class)->save($user, array_diff_key($this->address(), ['pin_confirmed' => true]));
        $this->actingAs($user)->withSession(['delivery_quote' => ['address' => 'Old destination']])
            ->patch('/account/addresses/'.$saved->id, $this->address(['street_address' => 'Changed street']))
            ->assertRedirect('/account/addresses')->assertSessionMissing('delivery_quote');
    }

    public function test_migration_copies_existing_profile_address_without_rewriting_profile(): void
    {
        $user = $this->customer(array_intersect_key($this->address(['phone' => '+639123456789']),
            array_flip(['phone', 'street_address', 'city', 'province', 'postal_code', 'country', 'lat', 'lng'])));
        $before = $user->fresh()->getAttributes();
        Schema::drop('shopping_carts');
        Schema::drop('customer_addresses');
        $migration = require database_path('migrations/2026_11_12_000028_create_customer_addresses_and_saved_carts.php');
        $migration->up();
        $saved = CustomerAddress::sole();
        $this->assertSame($user->id, $saved->user_id);
        $this->assertSame('09123456789', $saved->phone);
        $this->assertTrue($saved->is_default);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }
}
