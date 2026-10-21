<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CatalogBatch;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Local demo seeder only');
        }
        foreach ([['admin', 'admin@zoneone.test', 'Store Admin'], ['staff', 'staff@zoneone.test', 'Store Staff'], ['customer', 'customer@zoneone.test', 'Demo Customer']] as [$role,$email,$name]) {
            $user = User::firstOrNew(['email' => $email]);
            if (! $user->exists) {
                $user->name = $name;
                $user->password = 'ZoneOneDemo!2026';
                $user->role = $role;
                $user->email_verified_at = now();
                $user->save();
            }
        }
        app(CatalogBatch::class)->import();
    }
}
