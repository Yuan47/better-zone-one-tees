<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['customer', 'Why did an old store link take me to the updated website?',
                "1. The store can move its normal pages to an updated website. Use the store's confirmed current address.\n2. You may need to sign in again there, using your usual customer sign-in method. A website move does not create a new customer account.\n3. Open My orders to review your original invoices and payments. If you use a guest invoice, keep its private store-issued link safe and do not share it in chat.\n4. If an old sign-in attempt does not finish, open Sign in on the current website and start again. Never share your password, verification code, or recovery link.",
                'old website link, previous website address, store website moved, old sign-in link'],
            ['staff', 'Why can the two forecasts disagree after a low-sales month?',
                "1. Historical growth follows the change between the last two eligible completed months. A very low latest month can produce a sharp fall and a very small next-month estimate.\n2. Regression fits a straight trend across the wider eligible history, up to six completed months. It can therefore give a different estimate from the latest two-month change.\n3. Expand the exact monthly amounts and historical error. Check whether a low month represents genuine recorded sales or incomplete demonstration records before using the result to plan stock.\n4. Neither estimate guarantees future sales. Do not invent sales or change old payments to improve the chart. Simulated history demonstrates the formulas; real evaluation needs genuine sales and later periods that were not used to fit the forecast.",
                'forecasts disagree, low sales month, sparse forecast history, growth estimate very low, different forecast estimates'],
        ];

        foreach ($rows as $index => [$audience, $question, $answer, $keywords]) {
            // The unique question protects owner edits, custom audiences and intentional inactivity.
            DB::table('chatbot_faqs')->insertOrIgnore(compact('audience', 'question', 'answer', 'keywords') + [
                'active' => true, 'sort_order' => 760 + $index, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain approved guidance and later owner edits when reverting code.
    }
};
