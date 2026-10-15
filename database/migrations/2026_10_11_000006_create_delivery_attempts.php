<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_booking_context')->nullable();
        });
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider_delivery_id', 100)->unique();
            $table->string('provider_status', 30);
            $table->string('provider_mode', 20);
            $table->timestamp('checked_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_booking_context'));
    }
};
