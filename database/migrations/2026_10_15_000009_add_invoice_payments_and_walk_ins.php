<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('sales_channel', 20)->default('online')->index();
            $table->string('payment_plan', 20)->default('full');
            $table->unsignedInteger('amount_paid')->default(0);
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('original_due_at')->nullable();
            $table->timestamp('walk_in_received_at')->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->char('credit_phone_hash', 64)->nullable()->index();
            $table->uuid('creation_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('credit_locks', function (Blueprint $table) {
            $table->char('identity_hash', 64)->primary();
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->string('method', 20);
            $table->string('status', 20)->index();
            $table->boolean('legacy')->default(false);
            $table->unsignedInteger('amount');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('payment_id')->nullable()->unique();
            $table->timestamp('received_at')->nullable()->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cash_reason')->nullable();
        });
        Schema::table('order_reviews', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
        DB::table('orders')->where('payment_status', 'paid')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update(['amount_paid' => $order->total]);
                DB::table('payment_attempts')->insert([
                    'order_id' => $order->id, 'reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'paid',
                    'legacy' => true, 'amount' => $order->total, 'checkout_session_id' => $order->checkout_session_id,
                    'checkout_url' => $order->checkout_url, 'payment_id' => $order->payment_id,
                    'received_at' => $order->paid_at ?? $order->created_at,
                    'reason' => 'Existing confirmed payment preserved during invoice ledger upgrade.',
                    'created_at' => $order->created_at, 'updated_at' => $order->updated_at,
                ]);
            }
        });
        DB::table('orders')->where('payment_status', 'pending')->where('inventory_released', false)->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                DB::table('payment_attempts')->insert(['order_id' => $order->id, 'reference' => (string) Str::uuid(), 'method' => 'paymongo',
                    'status' => $order->checkout_session_id ? 'pending' : 'unknown', 'legacy' => true, 'amount' => $order->total,
                    'checkout_session_id' => $order->checkout_session_id, 'checkout_url' => $order->checkout_url,
                    'reason' => 'Existing unpaid/uncertain checkout retained for reconciliation.', 'created_at' => $order->created_at, 'updated_at' => $order->updated_at]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This migration retains financial history. Restore a reviewed backup rather than deleting payment receipts or guest orders.');
    }
};
