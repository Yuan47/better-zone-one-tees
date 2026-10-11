<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StructuredAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        $user = User::factory()->create(['password' => 'OriginalPassword!2026']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function profile(array $extra = []): array
    {
        return $extra + ['name' => 'Updated Customer', 'username' => 'updated_customer'];
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jkvkAAAAASUVORK5CYII='));
    }

    public static function roles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_and_update_its_own_account(string $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->get('/account')->assertOk()->assertSee('My Account')->assertSee($user->email);
        $this->patch('/account', $this->profile())->assertRedirect('/account');
        $this->assertSame('Updated Customer', $user->fresh()->name);
        $this->assertSame('updated_customer', $user->fresh()->username);
        $this->assertSame($role, $user->fresh()->role);
    }

    public function test_account_endpoints_require_sign_in(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->patch('/account', $this->profile())->assertRedirect('/login');
        $this->patch('/account/password', [])->assertRedirect('/login');
    }

    public function test_email_and_role_cannot_be_changed_through_profile(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patch('/account', $this->profile(['email' => 'attacker@example.com', 'role' => 'admin']))
            ->assertSessionHasErrors(['email', 'role']);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertNotSame('Updated Customer', $user->fresh()->name);
    }

    public function test_customer_address_is_saved_in_separate_fields_and_partial_address_is_rejected(): void
    {
        $user = $this->user();
        $address = ['street_address' => '12 Sample Street, Barangay Uno', 'city' => 'Quezon City', 'province' => 'Metro Manila',
            'postal_code' => '1100', 'country' => 'Philippines', 'phone' => '09123456789', 'lat' => 14.676, 'lng' => 121.0437];
        $this->actingAs($user)->patch('/account', $this->profile($address))->assertRedirect('/account');
        foreach ($address as $field => $value) {
            $this->assertEquals($value, $user->fresh()->getAttribute($field));
        }
        $this->assertSame('12 Sample Street, Barangay Uno, Quezon City, Metro Manila, 1100, Philippines', app(StructuredAddress::class)->format($address));
        $this->patch('/account', $this->profile(['street_address' => 'Changed street', 'country' => 'Philippines']))
            ->assertSessionHasErrors(['city', 'province', 'postal_code']);
        $this->assertSame($address['street_address'], $user->fresh()->street_address);
    }

    public function test_coordinates_must_be_a_valid_pair_and_staff_cannot_save_customer_address(): void
    {
        $this->actingAs($this->user())->patch('/account', $this->profile(['lat' => 14.5]))->assertSessionHasErrors('lng');
        $this->patch('/account', $this->profile(['lat' => 91, 'lng' => 121]))->assertSessionHasErrors('lat');
        $this->actingAs($this->user('staff'))->patch('/account', $this->profile(['city' => 'Quezon City']))->assertSessionHasErrors('city');
    }

    public function test_username_is_unique_and_normalized(): void
    {
        $first = $this->user();
        $first->update(['username' => 'existing_user']);
        $second = $this->user();
        $this->actingAs($second)->patch('/account', $this->profile(['username' => 'Existing_User']))->assertSessionHasErrors('username');
        $this->patch('/account', $this->profile(['username' => ' NEW_USER ']))->assertRedirect('/account');
        $this->assertSame('new_user', $second->fresh()->username);
    }

    public static function shortDisplayNames(): array
    {
        return [['A'], ['Li'], ['王']];
    }

    #[DataProvider('shortDisplayNames')]
    public function test_short_or_untransliterated_name_gets_a_usable_suggested_username(string $name): void
    {
        $user = $this->user();
        $user->update(['name' => $name]);
        $html = $this->actingAs($user)->get('/account')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="username"[^>]*value="([^"]+)"/', $html, $matches));
        $suggested = html_entity_decode($matches[1], ENT_QUOTES);
        $this->patch('/account', ['name' => $name, 'username' => $suggested])->assertRedirect('/account')->assertSessionHasNoErrors();
        $this->assertSame($suggested, $user->fresh()->username);
    }

    public function test_avatar_upload_uses_generated_path_and_replacement_cleans_old_photo(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->actingAs($user)->patch('/account', $this->profile(['avatar' => $this->png('personal-avatar.png')]))->assertRedirect('/account');
        $path = $user->fresh()->avatar_path;
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertStringNotContainsString('personal-avatar', $path);
        Storage::disk('uploads')->assertExists(substr($path, strlen('uploads/')));
        $this->patch('/account', $this->profile(['avatar' => $this->png('replacement.png')]))->assertRedirect('/account');
        $next = $user->fresh()->avatar_path;
        $this->assertNotSame($path, $next);
        Storage::disk('uploads')->assertMissing(substr($path, strlen('uploads/')));
        $this->patch('/account', $this->profile(['remove_avatar' => 1]))->assertRedirect('/account');
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('uploads')->assertMissing(substr($next, strlen('uploads/')));
    }

    public function test_non_image_svg_oversized_upload_and_direct_avatar_path_are_rejected(): void
    {
        Storage::fake('uploads');
        $user = $this->user();
        $this->actingAs($user)->patch('/account', $this->profile(['avatar' => UploadedFile::fake()->createWithContent('picture.png', '<?php echo "unsafe";')]))
            ->assertSessionHasErrors('avatar');
        $this->patch('/account', $this->profile(['avatar' => UploadedFile::fake()->createWithContent('picture.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]))
            ->assertSessionHasErrors('avatar');
        $oversize = $this->png();
        $oversize->size(2049);
        $this->patch('/account', $this->profile(['avatar' => $oversize]))->assertSessionHasErrors('avatar');
        $this->patch('/account', $this->profile(['avatar_path' => 'https://external.example/avatar.php']))->assertSessionHasErrors('avatar_path');
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertCount(0, Storage::disk('uploads')->allFiles());
    }

    public function test_password_change_requires_current_password_matching_confirmation_and_minimum_length(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patch('/account/password', ['password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])
            ->assertSessionHasErrors('current_password');
        $this->patch('/account/password', ['current_password' => 'WrongPassword', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])
            ->assertSessionHasErrors('current_password');
        $this->patch('/account/password', ['current_password' => 'OriginalPassword!2026', 'password' => 'NewPassword!2026', 'password_confirmation' => 'DifferentPassword!'])
            ->assertSessionHasErrors('password');
        $this->patch('/account/password', ['current_password' => 'OriginalPassword!2026', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('OriginalPassword!2026', $user->fresh()->password));
    }

    public function test_password_change_preserves_current_login_and_revokes_other_sessions(): void
    {
        $user = $this->user();
        $another = $this->user();
        DB::table('sessions')->insert([
            ['id' => 'another-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'another-user', 'user_id' => $another->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $token = $user->remember_token;
        $this->actingAs($user)->patch('/account/password', ['current_password' => 'OriginalPassword!2026', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])
            ->assertRedirect('/account');
        $this->assertTrue(Hash::check('NewPassword!2026', $user->fresh()->password));
        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'another-user']);
        $this->assertAuthenticatedAs($user);
    }
}
