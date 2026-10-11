<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_faqs')) {
            Schema::create('chatbot_faqs', function (Blueprint $table) {
                $table->id();
                $table->string('question', 250)->unique();
                $table->text('answer');
                $table->string('keywords', 500)->nullable();
                $table->boolean('active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        $raw = DB::table('shop_settings')->where('key', 'store_info')->value('value');
        $info = json_decode($raw ?? '{}', true);
        $info = is_array($info) ? $info : [];
        $legacy = DB::table('shop_settings')->where('key', 'chatbot_faq')->value('value');
        if (is_string($legacy) && trim($legacy) !== '' && empty($info['store_note'])) {
            $info['store_note'] = $legacy;
            DB::table('shop_settings')->updateOrInsert(['key' => 'store_info'], ['value' => json_encode($info, JSON_THROW_ON_ERROR)]);
        }
        // Keep the old record intact, and seed only facts already enforced by the application.
        if (! DB::table('chatbot_faqs')->exists()) {
            $rows = [
                ['What are the quantity pricing tiers?', 'Total pieces across the cart: 1–5 Retail/SRP, 6–50 Wholesale, 51–100 Re-seller, 101+ Bulk with 5% off the Re-seller clothing subtotal. Delivery is separate.', 'pricing, price, retail, wholesale, re-seller, reseller, bulk, quantity, discount'],
                ['How are payments confirmed?', 'PayMongo hosted checkout is used when payments are enabled. An order is confirmed as paid only after a verified PayMongo payment notification. Returning from checkout or sending a payment screenshot does not confirm payment.', 'payment confirmation, proof of payment, payment screenshot, paymongo'],
                ['Where can I see the size chart?', 'Open a product to see its Size / Width (in.) / Length (in.) table. Sample reference charts are labeled; blank measurements need store confirmation. Reference-only sizes are not necessarily available to order.', 'size chart, sizing, measurements, fit'],
                ['How can I track my order?', 'Sign in and open My orders to see your order status, or share your full order number (ZOT-…). Customers can only access their own orders.', 'track, tracking, order status'],
            ];
            foreach ($rows as $index => [$question, $answer, $keywords]) {
                DB::table('chatbot_faqs')->insert(['question' => $question, 'answer' => $answer, 'keywords' => $keywords,
                    'active' => true, 'sort_order' => $index * 10, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_faqs');
        // Preserve approved store information and the legacy record when rolling back.
    }
};
