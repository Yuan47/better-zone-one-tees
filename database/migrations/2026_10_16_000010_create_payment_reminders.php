<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // A snapshot must not acquire MariaDB's implicit first-TIMESTAMP update behavior.
            $table->dateTime('due_at');
            $table->unsignedTinyInteger('days_before');
            $table->uuid('claim_key')->unique();
            $table->string('recipient');
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('claimed')->index();
            $table->string('provider_message_id')->nullable()->unique();
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'due_at', 'days_before'], 'payment_reminders_invoice_due_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
