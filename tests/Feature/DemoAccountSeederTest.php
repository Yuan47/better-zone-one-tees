<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogBatch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = [
        'admin@zoneone.test' => ['admin', 'Store Admin'],
        'staff@zoneone.test' => ['staff', 'Store Staff'],
        'customer@zoneone.test' => ['customer', 'Demo Customer'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    public static function demoEnvironments(): array
    {
        return [['local'], ['testing']];
    }

    #[DataProvider('demoEnvironments')]
    public function test_new_demo_accounts_are_verified_only_in_supported_demo_environments(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->travelTo(now()->startOfSecond());
        $this->mock(CatalogBatch::class)->shouldReceive('import')->once()->andReturn([]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        foreach (self::FIXTURES as $email => [$role, $name]) {
            $user = User::where('email', $email)->sole();
            $this->assertSame($role, $user->role);
            $this->assertSame($name, $user->name);
            $this->assertTrue($user->email_verified_at->equalTo(now()));
        }
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_existing_demo_accounts_keep_all_original_attributes_and_verification_state(): void
    {
        $this->freezeTime();
        $originals = [];
        foreach (array_keys(self::FIXTURES) as $index => $email) {
            $user = User::factory()->create(['email' => $email, 'name' => 'Existing fixture '.$index,
                'username' => 'existing_fixture_'.$index, 'email_verified_at' => $index === 1 ? now()->subMonth() : null]);
            $user->forceFill(['role' => 'customer'])->save();
            $originals[$user->id] = $user->fresh()->getRawOriginal();
        }
        $this->travel(1)->day();
        $this->mock(CatalogBatch::class)->shouldReceive('import')->once()->andReturn([]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        foreach ($originals as $id => $attributes) {
            $this->assertSame($attributes, User::findOrFail($id)->getRawOriginal());
        }
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_production_refuses_demo_seeding_before_creating_accounts_or_importing_catalog(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->mock(CatalogBatch::class)->shouldNotReceive('import');

        try {
            app(DatabaseSeeder::class)->run();
            $this->fail('Production must refuse local demo fixtures.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Local demo seeder only', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
