<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('provider_completed_at')->nullable();
            $table->string('delivery_last_status', 80)->nullable();
            $table->timestamp('customer_confirmed_at')->nullable()->index();
        });
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->timestamps();
        });
        // Legacy completion has no customer receipt/review; do not invent either.
        DB::table('orders')->where('status', 'completed')->whereNull('customer_confirmed_at')
            ->update(['delivery_last_status' => 'LEGACY_COMPLETED_AWAITING_CUSTOMER',
                'status' => DB::raw("CASE WHEN delivery_method = 'lalamove' THEN 'out_for_delivery' ELSE 'ready' END")]);
        DB::table('orders')->where('payment_status', 'paid')->where('status', 'to_pack')->update(['status' => 'packing']);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['provider_completed_at', 'delivery_last_status', 'customer_confirmed_at']);
        });
    }
};
