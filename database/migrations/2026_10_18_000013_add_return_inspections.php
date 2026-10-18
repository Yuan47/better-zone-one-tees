<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $table->json('items');
            $table->unsignedInteger('clothing_value');
            $table->unsignedInteger('delivery_value')->default(0);
            $table->text('inspection_note');
            $table->string('photo_path')->nullable();
            $table->text('photo_exception_reason')->nullable();
            $table->unsignedTinyInteger('policy_days');
            $table->boolean('policy_delivery_eligible');
            $table->dateTime('delivered_at');
            $table->dateTime('deadline_at');
            $table->dateTime('requested_at');
            $table->text('late_exception_reason')->nullable();
            $table->dateTime('inspected_at');
            $table->timestamps();
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('return_inspection_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('customer_photo_path')->nullable();
            $table->unsignedTinyInteger('policy_days')->nullable();
            $table->boolean('policy_delivery_eligible')->nullable();
            $table->timestamp('policy_delivered_at')->nullable();
            $table->timestamp('policy_deadline_at')->nullable();
            $table->boolean('policy_late')->default(false);
            $table->text('late_exception_reason')->nullable();
            $table->unsignedInteger('clothing_amount')->nullable();
            $table->unsignedInteger('delivery_amount')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Return inspections and refund allocations are permanent financial evidence. Restore a verified backup instead of rolling this migration back.');
    }
};
