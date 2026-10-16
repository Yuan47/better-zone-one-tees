<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 254)->unique();
            $table->string('password_hash')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('nonce')->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('resend_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('send_window_started_at')->nullable();
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->dateTime('consumed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
