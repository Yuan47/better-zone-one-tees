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
        $this->patch('/account/addresses/'.$work->id, $this->address(['label' => 'Work', 'street_address' => '21 Office Street']))->assertRedirect('/account/addresses');
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

    public function test_customer_can_create_and_update_each_of_the_three_address_labels(): void
    {
        $this->actingAs($this->customer());
        foreach (['Home', 'Work', 'Dropoff Point'] as $label) {
            $this->post('/account/addresses', $this->address(['label' => $label]))
                ->assertRedirect('/account/addresses')->assertSessionHasNoErrors();
            $saved = CustomerAddress::latest('id')->firstOrFail();
            $this->assertSame($label, $saved->label);
            $before = $saved->fresh()->getAttributes();
            $this->patch('/account/addresses/'.$saved->id, $this->address(['label' => $label]))
                ->assertRedirect('/account/addresses')->assertSessionHasNoErrors();
            $this->assertSame(array_diff_key($before, ['updated_at' => true]),
                array_diff_key($saved->fresh()->getAttributes(), ['updated_at' => true]));
        }
        $this->assertDatabaseCount('customer_addresses', 3);
    }

    public function test_unlisted_address_labels_are_rejected_on_both_create_and_update_without_changing_saved_details(): void
    {
        $user = $this->customer();
        $saved = app(CustomerAddresses::class)->save($user, array_diff_key($this->address(), ['pin_confirmed' => true]));
        $before = $saved->fresh()->getAttributes();
        $this->actingAs($user);
        foreach (['Office', 'home', 'Drop-off Point', '', null, ['Home']] as $label) {
            $this->post('/account/addresses', $this->address(['label' => $label]))->assertSessionHasErrors('label');
            $this->patch('/account/addresses/'.$saved->id, $this->address(['label' => $label, 'street_address' => 'Must not be saved']))
                ->assertSessionHasErrors('label');
            $this->assertDatabaseCount('customer_addresses', 1);
            $this->assertSame($before, $saved->fresh()->getAttributes());
        }
    }

    public function test_form_has_only_allowed_label_choices_and_legacy_labels_require_an_explicit_choice_without_get_writes(): void
    {
        $user = $this->customer();
        $legacy = app(CustomerAddresses::class)->save($user, array_diff_key($this->address(['label' => 'Office']), ['pin_confirmed' => true]));
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($user);
        foreach (['/account/addresses/create', '/account/addresses/'.$legacy->id.'/edit'] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//label[@for="saved-address-label"]')->length);
            $this->assertSame(1, $xpath->query('//select[@id="saved-address-label"][@name="label"][@required]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="label"]|//datalist[@id="address-labels"]')->length);
            $labels = array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="label"]/option[@value!=""]')));
            $this->assertSame(['Home', 'Work', 'Dropoff Point'], $labels);
            if (str_ends_with($url, '/edit')) {
                $this->assertSame(1, $xpath->query('//select[@name="label"]/option[@value=""][@selected][@disabled]')->length);
                $response->assertSee('Choose Home, Work, or Dropoff Point before saving this address.');
                $this->assertSame(1, $xpath->query('//select[@name="label"][@aria-describedby="address-label-help"]')->length);
            } else {
                $this->assertSame(1, $xpath->query('//select[@name="label"]/option[@value="Home"][@selected]')->length);
            }
        }
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->patch('/account/addresses/'.$legacy->id, $this->address(['label' => 'Office']))->assertSessionHasErrors('label');
        $this->patch('/account/addresses/'.$legacy->id, $this->address(['label' => 'Dropoff Point']))
            ->assertRedirect('/account/addresses')->assertSessionHasNoErrors();
        $this->assertSame('Dropoff Point', $legacy->fresh()->label);
        $this->assertSame(array_diff_key($before, ['label' => true, 'updated_at' => true]),
            array_diff_key($legacy->fresh()->getAttributes(), ['label' => true, 'updated_at' => true]));
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
