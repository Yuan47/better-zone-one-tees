<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->uuid('nonce');
            $table->char('code_hash', 64)->nullable();
            $table->string('status', 20)->default('claimed');
            $table->dateTime('expires_at');
            $table->dateTime('resend_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('send_window_started_at');
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
