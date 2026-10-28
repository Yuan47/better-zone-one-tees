<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // An existing question can contain an owner-approved edit or be deliberately inactive.
        DB::table('chatbot_faqs')->insertOrIgnore([
            'question' => 'What should I do if my payment receipt email is missing?',
            'answer' => '1. Sign in, open My orders, and open the original invoice. In Invoice payments, only Payment received entries confirm money received; an email or checkout return alone does not confirm payment.'
                ."\n2. Each received installment has its own receipt reference and amount on the same invoice. A store-issued email, when enabled, shows the balance after that payment; later payments or refunds appear on the invoice."
                ."\n3. Check your account inbox and spam folder. The optional Zone One Tees receipt email is a separate acknowledgment from PayMongo email; email delivery can be delayed or fail."
                ."\n4. If it is still missing, contact the store with the ZOT invoice number and receipt reference. Never pay again just to obtain an email, and never share your password, verification code, or private receipt link in chat.",
            'keywords' => 'missing receipt email, payment receipt email missing, paymongo receipt email, no receipt email, balance receipt email, installment receipt email',
            'audience' => 'customer', 'active' => true, 'sort_order' => 600,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve editable store guidance when reverting code.
    }
};
