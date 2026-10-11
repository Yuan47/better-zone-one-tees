<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('street_address', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Philippines');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('street_address', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'avatar_path', 'phone', 'street_address', 'city', 'province', 'postal_code', 'country', 'lat', 'lng']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['street_address', 'city', 'province', 'postal_code', 'country']));
    }
};
