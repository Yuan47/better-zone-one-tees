<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('customer')->index();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('category')->index();
            $t->text('description');
            $t->string('art_color')->default('#d8cdbb');
            $t->string('image_url')->nullable();
            $t->boolean('active')->default(true)->index();
            $t->timestamps();
        });
        Schema::create('variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('sku')->unique();
            $t->string('size');
            $t->string('color');
            foreach (['retail', 'wholesale', 'reseller'] as $tier) {
                $t->unsignedInteger($tier.'_price');
            }
            $t->unsignedInteger('stock')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->unsignedInteger('reorder_level')->default(10);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('tier');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('subtotal');
            $t->unsignedInteger('merchandise_base');
            $t->unsignedInteger('discount')->default(0);
            $t->unsignedInteger('shipping')->default(0);
            $t->unsignedInteger('total');
            $t->string('payment_status')->default('pending')->index();
            $t->string('status')->default('awaiting_payment')->index();
            $t->string('recipient');
            $t->string('phone');
            $t->text('address');
            $t->string('delivery_method')->default('pickup');
            $t->string('lat')->nullable();
            $t->string('lng')->nullable();
            $t->json('delivery_quote')->nullable();
            $t->string('delivery_id')->nullable()->unique();
            $t->text('tracking_url')->nullable();
            $t->string('checkout_session_id')->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->string('payment_id')->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->boolean('inventory_committed')->default(false);
            $t->boolean('inventory_released')->default(false);
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('size');
            $t->string('color');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('unit_price');
            $t->unsignedInteger('total');
            $t->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unique(['user_id', 'product_id']);
            $t->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->string('type');
            $t->string('outcome');
            $t->timestamps();
        });
        Schema::create('shop_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
    }

    public function down(): void
    {
        foreach (['shop_settings', 'webhook_events', 'wishlists', 'order_items', 'orders', 'variants', 'products'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
