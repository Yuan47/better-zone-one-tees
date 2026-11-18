<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Update untouched shipped guidance; retain owner-written answers and audiences.
    public const REFRESH = array (
  0 => 
  array (
    'question' => 'How do staff pack an order and mark it ready?',
    'previous_sha256' => '1d59baa806eb9f3c45b7dfb6bd314a50fb528706af9fe3ba7219b4140ed2bdda',
    'answer' => '1. Open Orders and select the invoice. Confirm it is fully paid.
2. Check every size, color, and quantity, then pack the clothing.
3. If fulfillment is on hold for a refund, ask the owner to review it before proceeding.
4. When the action is available, check the name under Marked by and choose Mark Ready for delivery. The signed-in staff member or owner is recorded automatically. Do not mark ready before the packing check is complete.',
  ),
  1 => 
  array (
    'question' => 'How do we demonstrate Lalamove booking without a real delivery?',
    'previous_sha256' => 'd56103ab8942e8f0e943fe4327a350f51fbe179c8ca71d4f90822fccf8b919d5',
    'answer' => '1. Confirm the store is using Lalamove Sandbox, the approved pickup entrance and the TEST contact number. In the Philippines Partner Portal, select Sandbox; use only its virtual test credit.
2. Use a fully paid TEST order with a complete address and confirmed map pin. Pack it and choose Mark Ready for delivery. Check the signed-in name under Marked by; it is recorded automatically. Review the saved delivery fee, then choose Book Lalamove sandbox delivery once.
3. Open the same Sandbox booking in the Partner Portal. Use its test controls to simulate rider progress, then Check delivery with Lalamove on the store order. If the first booking is uncertain, reconcile or recover it before rebooking. A higher quote needs owner approval to cover the difference.
4. To Ship remains open until the customer confirms receipt with a star rating after verified courier completion. This is a simulation only: do not request a real courier, use a real wallet or claim a physical delivery occurred.',
  ),
  2 => 
  array (
    'question' => 'What can staff do, and when should they ask the owner?',
    'previous_sha256' => '65ec0565cac5916f362109b97c49159ecc9f6b8f6975d2c303f23b9ffd0f26ff',
    'answer' => '1. Staff can use walk-in checkout, record full cash actually received, check payments, pack paid orders, mark ready with their signed-in name recorded automatically, and book or reconcile eligible deliveries.
2. Staff can review customer orders, reviews, assigned reports and forecasts. Only the customer submits receipt confirmation and the customer rating for an online order.
3. Ask the owner for product or stock changes, archive/delete actions, staff setup, settings, FAQ changes, return inspection/approval, cash refunds or approval to cover a higher courier quote.
4. Use only the actions your signed-in role permits. The assistant explains steps; it cannot charge money, approve refunds, edit stock or grant permissions.',
  ),
  3 => 
  array (
    'question' => 'Why can I not mark an order Ready or book a courier?',
    'previous_sha256' => 'b4113791d8ce2ec658470c2eb937f0d9ca9d220769e48008a744b88da8c4ed29',
    'answer' => '1. Open the order and verify full payment. Pending or uncertain online attempts and payment screenshots do not qualify.
2. Check the order stage, correct sizes/colors/quantities and completed packing. Check the name under Marked by, then choose Mark Ready for delivery. The system records the signed-in user automatically.
3. A refund fulfillment hold or unresolved existing courier booking blocks normal progression. Reconcile the existing record and ask the owner about a hold instead of creating another order.
4. Lalamove booking also needs a valid quote and delivery details. A higher courier quote needs owner cost approval. Store pickup does not use Lalamove; online customers confirm receipt after ready pickup or verified courier completion.',
  ),
);

    public const ADDITIONS = [];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::REFRESH as $change) {
                $faq = DB::table('chatbot_faqs')->where('question', $change['question'])->lockForUpdate()->first();
                if ($faq && $faq->question === $change['question']
                    && hash_equals($change['previous_sha256'], hash('sha256', str_replace("\r\n", "\n", $faq->answer)))) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update([
                        'answer' => $change['answer'], 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep corrected guidance and subsequent owner edits.
    }
};
