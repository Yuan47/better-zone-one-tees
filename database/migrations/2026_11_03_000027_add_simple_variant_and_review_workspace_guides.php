<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $question = 'How does the owner add a product and its size and color variants?';
            $previous = "1. Open Products and choose Add product, or open an existing product to edit it.\n2. Save the product details, photo, and measurements that you have confirmed.\n3. Use Add a size & color variant for each sellable combination. Enter its size, color group, color, prices, stock, and reorder threshold; the SKU is assigned automatically.\n4. Save and review the variants. Add another color copies existing colored sizes and prices, so review each new variant stock and price before selling.";
            $answer = "1. Open Products and choose Add product, or open an existing product to edit it.\n2. Save the product details, photo, and confirmed size chart.\n3. Use Add a size & color variant for each combination. Enter its size and actual color, including White just like Red or any other color, then enter prices, counted stock and reorder threshold. The SKU is automatic; no White/Colored group selection is needed.\n4. To add a color across sizes, choose Add another color and select the existing color whose sizes and prices should be copied. Review those prices and enter counted stock before selling; new color variants start at zero stock.";
            $faq = DB::table('chatbot_faqs')->where('question', $question)->lockForUpdate()->first();
            if ($faq && $faq->question === $question && $faq->answer === $previous) {
                DB::table('chatbot_faqs')->where('id', $faq->id)->update(compact('answer'));
            }

            $rows = [
                ['customer', 'How do I choose a Dannon neckline and color?',
                    'Choose the separate Dannon Round Neck or Dannon V-Neck product, and choose its adult or kids version. On that product, choose Color and Size; White is in the same color list as the other colors. Read the selected size/color price and availability, because prices can differ by color and size. Quantity pricing still uses the total clothing pieces in your cart. A new V-Neck product can be out of stock until the owner enters its counted inventory.',
                    'Dannon neckline, round neck v-neck separate products, kids neckline, White color selection'],
                ['owner', 'How do I add White or another color to a product?',
                    "1. Open Products and edit the product. White is a normal color, so there is no White/Colored group to choose.\n2. For one combination, use Add a size & color variant and enter the actual color, size, prices and counted stock.\n3. For several sizes, use Add another color, type the new color and select the existing color to copy sizes and prices from. Choose the source carefully when White and other colors have different prices.\n4. Review the created variants and enter counted stock. Copied variants start at zero stock; copying prices does not create physical inventory.",
                    'add White color, normal color variant, template color, copy sizes prices, add another color'],
                ['staff', 'How can staff and the owner read customer product reviews?',
                    "1. Open Customer reviews in the store workspace.\n2. Choose a product or star rating to narrow the list. Read the rating, optional written review and purchased items.\n3. Open the related order when you need its transaction details. Ratings are submitted by the customer with receipt confirmation; they cover the whole order, rather than a separate rating for every item.\n4. Star-only reviews remain valid, and a refund does not hide a negative review. The review page is read-only. Private refund proof is available separately through authorized refund review, not in product reviews.",
                    'staff customer reviews, owner read reviews, product feedback, review workspace'],
                ['staff', 'What happens to old Dannon sales after the necklines are separated?',
                    'Earlier combined Dannon orders keep their original product and variant IDs and saved invoice descriptions. They are not duplicated or automatically divided between the new necklines because those records do not identify a separate Round Neck or V-Neck choice. The original record is now the Round Neck listing, so its earlier history can include combined-neckline sales. New V-Neck products begin with no invented sales history and zero stock until counted inventory is entered. Interpret forecasts using that history limitation and compare genuine post-separation sales before drawing conclusions about each neckline.',
                    'old Dannon combined sales, neckline split history, Round Neck forecast history, V-Neck new history'],
            ];
            foreach ($rows as $index => [$audience, $question, $answer, $keywords]) {
                DB::table('chatbot_faqs')->insertOrIgnore(compact('audience', 'question', 'answer', 'keywords') + [
                    'active' => true, 'sort_order' => 800 + $index, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep corrected guidance and later owner edits.
    }
};
