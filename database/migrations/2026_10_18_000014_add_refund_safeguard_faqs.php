<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['How are returned clothes checked before a refund?', 'The invoice must be fully paid and Delivered. The owner records the returned clothing, quantities, condition and inspection notes before approving a refund. Inspection evidence is private; a missing photo needs a recorded owner explanation. The same inspection can support refunds against different installment receipts without counting the clothing twice. Approval is limited by the inspected eligible value and the original payments. Returning money does not automatically restock clothing.', 'return inspection, returned clothing, returned items, refund inspection, physical return'],
            ['What is the standard return-request window?', 'The default standard window is 7 days after confirmed delivery or a completed walk-in handover. The owner can change the policy for future requests. Your request records its applicable deadline and whether delivery charges are eligible; clothing and delivery are eligible by default. Late requests can still be submitted, but approval requires a recorded owner exception. Eligibility and the amount remain subject to owner review.', 'return window, return deadline, late return, seven days, 7 days, delivery fee refund'],
            ['Does an approved refund mean the money has arrived?', 'Approval records the store decision; it does not transfer money. The owner issues an online refund in PayMongo and verifies its reference against the original payment. Pending or processing is not a confirmed refund. PayMongo confirmation is shown separately, and the time for the credit to appear depends on the payment provider. Cash returns are recorded by the owner after handing back the money, with a reason.', 'refund approved, refund processing, refund pending, refund arrived, cash refund, refund confirmation'],
        ];
        foreach ($rows as $index => [$question, $answer, $keywords]) {
            // A duplicate question may already contain an owner-approved answer.
            DB::table('chatbot_faqs')->insertOrIgnore(['question' => $question, 'answer' => $answer, 'keywords' => $keywords,
                'active' => true, 'sort_order' => 260 + $index * 10, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Retain owner-editable FAQ records when reverting code.
    }
};
