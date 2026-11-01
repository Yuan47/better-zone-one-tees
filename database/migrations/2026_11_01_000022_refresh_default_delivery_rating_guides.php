<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['How do I confirm receipt of an online order?',
                'After receiving your clothing, open the order in My orders. Once the courier confirms completion or your pickup order is ready, use Confirm Delivered & submit review. You must submit a rating and review before closing the order as Delivered. A courier completion update alone does not close a customer online order. Fully paid walk-in invoices use the separate payment-received rule and are marked Delivered automatically.',
                'After receiving and checking your clothing, open the fully paid order in My orders. Once courier completion is verified or your pickup order is ready, choose a rating from 1 to 5 stars, tick the confirmation that you received the order, and choose Confirm Delivered. The written review is optional. A courier completion update alone does not close a customer online order. Fully paid walk-in invoices use the separate payment-received rule and are marked Delivered automatically.'],
            ['When can I request a refund?',
                'Refund requests are available only after the order is Delivered. For an online order, first confirm that you received the clothing and submit its required review. Open the delivered order to submit a refund request and its reason. The owner reviews requests; submitting one does not guarantee approval or immediate payment. Store staff can explain any additional approved return policy.',
                'Refund requests are available only after the order is Delivered. For an online order, first choose a star rating and confirm that you received the clothing. The written review is optional. Open the delivered order to submit a refund request and its reason. The owner reviews requests; submitting one does not guarantee approval or immediate payment. Store staff can explain any additional approved return policy.'],
            ['How do I confirm delivery and leave my review?',
                "1. Receive and check your clothing.\n2. Sign in and open the order in My orders.\n3. Once courier completion is verified or pickup is ready, choose Confirm Delivered & submit review.\n4. Submit the required rating and review. This closes an online order as Delivered; a courier update alone does not. Fully paid walk-in sales follow their separate handover rule.",
                "1. Receive and check your clothing.\n2. Sign in and open the fully paid order in My orders.\n3. Once courier completion is verified or pickup is ready, choose a rating from 1 to 5 stars. The written review is optional.\n4. Tick the confirmation that you received the order, then choose Confirm Delivered. This closes an online order as Delivered; a courier update alone does not. Fully paid walk-in sales follow their separate handover rule."],
            ['How do I ask for a refund or return?',
                "1. Open your fully paid, Delivered order in My orders. For an online order, first confirm receipt and submit the required review.\n2. Read the return policy and choose Request a refund or return.\n3. Enter your reason, optionally add a supporting photo, and send the request for review.\n4. Follow the store instructions for returning the clothing and check the decision on your order. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately.",
                "1. Open your fully paid, Delivered order in My orders. For an online order, first choose a star rating and confirm receipt. The written review is optional.\n2. Read the return policy and choose Request a refund or return.\n3. Enter your reason, optionally add a supporting photo, and send the request for review.\n4. Follow the store instructions for returning the clothing and check the decision on your order. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately."],
            ['How do staff help a customer confirm an online order was received?',
                "1. Open the order and check that courier completion is verified or pickup is ready.\n2. Ask the customer to sign in to their own account and open that order in My orders.\n3. The customer chooses Confirm Delivered & submit review and submits a rating and review after receiving the clothing.\n4. Check the updated order status. Staff cannot submit the customer receipt or review for them; a courier completion update alone does not close an online order.",
                "1. Open the fully paid order and check that courier completion is verified or pickup is ready.\n2. Ask the customer to sign in to their own account and open that order in My orders.\n3. After receiving the clothing, the customer chooses a rating from 1 to 5 stars, ticks the receipt confirmation, and chooses Confirm Delivered. The written review is optional.\n4. Check the updated order status. Staff cannot submit the customer receipt or rating for them; a courier completion update alone does not close an online order."],
        ];

        DB::transaction(function () use ($rows) {
            foreach ($rows as [$question, $previous, $updated]) {
                $faq = DB::table('chatbot_faqs')->where('question', $question)->lockForUpdate()->first();
                // Exact comparisons preserve owner edits, including case changes.
                // Only answer changes: audience, visibility and ordering stay intact.
                if ($faq && $faq->question === $question && $faq->answer === $previous) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update(['answer' => $updated]);
                }
            }
        });
    }

    public function down(): void
    {
        // Retain corrected guidance and any later owner edits.
    }
};
