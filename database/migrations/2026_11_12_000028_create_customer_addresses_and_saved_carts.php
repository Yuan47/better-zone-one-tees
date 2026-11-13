<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->default('Home');
            $table->string('recipient', 100);
            $table->string('phone', 30)->nullable();
            $table->string('street_address', 200);
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Philippines');
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });
        Schema::create('shopping_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->json('items');
            $table->timestamps();
        });

        // Keep the former profile address available for review without editing old invoices.
        DB::table('users')->where('role', 'customer')->whereNotNull('street_address')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                if (trim((string) $user->street_address) === '') {
                    continue;
                }
                $phone = (string) $user->phone;
                if (preg_match('/^\+639([0-9]{9})$/D', $phone, $matches)) {
                    $phone = '09'.$matches[1];
                }
                DB::table('customer_addresses')->insert([
                    'user_id' => $user->id, 'label' => 'Home', 'recipient' => $user->name,
                    'phone' => $phone === '' ? null : $phone, 'street_address' => $user->street_address,
                    'city' => $user->city, 'province' => $user->province, 'postal_code' => $user->postal_code,
                    'country' => $user->country ?: 'Philippines', 'lat' => $user->lat, 'lng' => $user->lng,
                    'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_carts');
        Schema::dropIfExists('customer_addresses');
    }
};
