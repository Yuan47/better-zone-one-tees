<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ready_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('claim_key')->unique();
            $table->string('recipient')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable()->unique();
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ready_notifications');
    }
};
