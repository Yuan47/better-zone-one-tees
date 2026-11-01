<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['staff', 'What should staff do if the courier quote increases?',
                "1. Open the fully paid order after packing is complete.\n2. Try the normal courier booking. If the fresh quote is higher than the delivery fee paid, no courier is booked and the order stays Ready for delivery.\n3. Ask the owner to review covering the difference. Staff cannot approve the extra cost or change the customer's invoice.\n4. If a booking response is uncertain, check or recover the original booking instead of requesting another courier.",
                'higher courier quote, delivery fee increase, courier cost, staff delivery cost help'],
            ['owner', 'How does the owner cover an increased courier quote?',
                "1. Open the fully paid, Ready for delivery order. Read the latest quote shown if a normal booking was blocked.\n2. Expand Approve a higher courier cost. Enter the maximum TOTAL courier fee, including the original delivery fee already paid, rather than only the difference.\n3. Enter a clear reason and confirm that the store will cover the extra cost. Choose Approve limit & book courier.\n4. The fresh quote must be within your limit. This approval is recorded for one booking only; replacement bookings need a new approval. The customer's clothing prices, invoice total, received payments and refund entitlement stay unchanged. A booking quote is not a store profit figure.",
                'approve courier cost, owner courier budget, absorb delivery difference, higher courier quote approval'],
        ];
        foreach ($rows as $index => [$audience, $question, $answer, $keywords]) {
            if (! DB::table('chatbot_faqs')->where('question', $question)->exists()) {
                DB::table('chatbot_faqs')->insert(compact('audience', 'question', 'answer', 'keywords') + [
                    'active' => true, 'sort_order' => 740 + $index, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep approved assistance and later owner edits.
    }
};
