<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('claim_key')->unique();
            $table->string('status', 20)->default('queued')->index();
            $table->string('recipient', 254)->nullable();
            $table->uuid('receipt_reference');
            $table->string('payment_id');
            $table->string('payment_mode', 4);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('balance_after');
            $table->dateTime('received_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('backfill_reason')->nullable();
            $table->text('retry_reason')->nullable();
            $table->unsignedSmallInteger('last_rejected_status')->nullable();
            $table->string('provider_message_id')->nullable()->unique();
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt email history must be preserved. Restore a reviewed backup instead of removing delivery claims.');
    }
};
