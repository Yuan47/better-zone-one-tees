<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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
                $user->save();
            }
        }
        $catalog = json_decode(file_get_contents(database_path('data/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $colors = ['#cfbfa8', '#242933', '#bac9c4', '#939daf', '#c79c88', '#ddcfb9', '#799295', '#c1a896'];
        foreach ($catalog as $i => $line) {
            $product = Product::firstOrCreate(['slug' => Str::slug($line['name'])], [
                'name' => $line['name'], 'category' => $line['category'],
                'description' => 'A comfortable everyday essential from our '.$line['category'].' collection. Mix sizes and colors in your cart and your quantity price applies automatically. Sample product for the local demo. Exact measurements and final product photos are pending store review.',
                'art_color' => $colors[$i % count($colors)],
            ]);
            foreach ($line['variants'] as $variant) {
                $product->variants()->firstOrCreate(['sku' => $variant['sku']], $variant + ['stock' => config('shop.demo') ? 120 : 0, 'reserved' => 0]);
            }
        }
    }
}
