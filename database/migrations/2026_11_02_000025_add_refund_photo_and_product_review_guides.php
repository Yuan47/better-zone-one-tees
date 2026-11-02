<?php

use App\Services\StoreKnowledge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $previous = [
                'When can I request a refund?' => [
                    'Refund requests are available only after the order is Delivered. For an online order, first choose a star rating and confirm that you received the clothing. The written review is optional. Open the delivered order to submit a refund request and its reason. The owner reviews requests; submitting one does not guarantee approval or immediate payment. Store staff can explain any additional approved return policy.',
                    'Refund requests are available only after the order is fully paid and Delivered. For an online order, first choose a star rating and confirm that you received the clothing. The written review is optional. Open the delivered order to submit a refund request with its reason and a required clear supporting photo. The photo is private store-review evidence and does not appear in public reviews. The owner reviews eligibility; a request does not guarantee approval or immediate payment.',
                ],
                'How do I ask for a refund or return?' => [
                    "1. Open your fully paid, Delivered order in My orders. For an online order, first choose a star rating and confirm receipt. The written review is optional.\n2. Read the return policy and choose Request a refund or return.\n3. Enter your reason, optionally add a supporting photo, and send the request for review.\n4. Follow the store instructions for returning the clothing and check the decision on your order. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately.",
                    "1. Open your fully paid, Delivered order in My orders or your private guest invoice. For an online order, first choose a star rating and confirm receipt. The written review is optional.\n2. Read the return policy and choose Request a refund or return.\n3. Enter your reason and upload the required clear supporting photo of the clothing or issue (JPG, JPEG, PNG or WebP, up to 3 MB and 6000 pixels per side). Send the request for review.\n4. Follow the store instructions for returning the clothing and check the decision on your order. Photos are private. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately.",
                ],
            ];
            foreach ($previous as $question => [$old, $answer]) {
                $faq = DB::table('chatbot_faqs')->where('question', $question)->lockForUpdate()->first();
                // Refresh only untouched defaults. Retain owner wording and visibility decisions.
                if ($faq && $faq->question === $question && $faq->answer === $old) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update(['answer' => $answer]);
                }
            }

            $rows = [
                ['Is a photo required for a refund or return request?', StoreKnowledge::REFUND_PHOTO_HELP,
                    'refund photo required, return picture proof, supporting photo, refund evidence'],
                ['Where can I see customer ratings and reviews for an item?',
                    'Open the product and scroll below its details and size chart to Customer reviews. The summary and star counts use verified completed customer orders containing that product. Ratings belong to the whole order, not a separate rating of each item. Written reviews are optional, so some entries show stars only. A mixed order can appear on each product it contained, once per product. Buyer names are masked; emails, invoice numbers and private refund photos are not published. After receiving an eligible fully paid online order, open it in My orders to choose stars and confirm receipt. Product reviews do not let you review an order you did not purchase.',
                    'customer product reviews, item ratings, verified purchase, stars only, where reviews'],
            ];
            foreach ($rows as $index => [$question, $answer, $keywords]) {
                DB::table('chatbot_faqs')->insertOrIgnore(compact('question', 'answer', 'keywords') + [
                    'audience' => 'customer', 'active' => true, 'sort_order' => 780 + $index,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve accurate guidance and subsequent owner edits.
    }
};
