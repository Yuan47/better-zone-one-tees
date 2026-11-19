<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Exact answer hashes retain owner-written instructions and metadata.
    public const REFRESH = [
        [
            'question' => 'How do I confirm receipt of an online order?',
            'previous_sha256' => '0418a246c0464e13871eb014f192f5689d08d8a4e355a6ecdff191536bb62691',
            'answer' => '1. Sign in to the customer account used for the purchase. Open My Purchases and choose Order received for the matching order, or open the order and scroll to Order received.
2. Confirm only after receiving and checking the clothing. The order must be fully paid; Lalamove completion must be verified, or store pickup must be ready. Until then the action is unavailable.
3. Choose a 1 to 5 star rating. The written review is optional. Tick I have received my order, then choose Confirm Delivered.
4. The order shows Delivered in Completed purchases with the customer confirmation date. Return / refund instructions are on the same order. Fully paid walk-in handovers use their separate payment-received completion rule; staff cannot submit an online customer rating or receipt.',
        ],
        [
            'question' => 'How do I confirm delivery and leave my review?',
            'previous_sha256' => '9ddd4a8fe449b9d6cbda90509071ea775bc27a74db65493e89427f070a3d21a6',
            'answer' => '1. Sign in to the customer account used for the purchase. Open My Purchases and choose Order received for the matching order, or open the order and scroll to Order received.
2. Confirm only after receiving and checking the clothing. The order must be fully paid; Lalamove completion must be verified, or store pickup must be ready. Until then the action is unavailable.
3. Choose a 1 to 5 star rating. The written review is optional. Tick I have received my order, then choose Confirm Delivered.
4. The order shows Delivered in Completed purchases with the customer confirmation date. Return / refund instructions are on the same order. Fully paid walk-in handovers use their separate payment-received completion rule; staff cannot submit an online customer rating or receipt.',
        ],
        [
            'question' => 'Why can I not confirm receipt yet?',
            'previous_sha256' => '58ce639a38d3583b2085e622b880ddda212e8c886988b7363423d0370d3d206d',
            'answer' => '1. Sign in to the customer account used for the purchase. Open My Purchases and choose Order received for the matching order, or open the order and scroll to Order received.
2. Confirm only after receiving and checking the clothing. The order must be fully paid; Lalamove completion must be verified, or store pickup must be ready. Until then the action is unavailable.
3. Choose a 1 to 5 star rating. The written review is optional. Tick I have received my order, then choose Confirm Delivered.
4. The order shows Delivered in Completed purchases with the customer confirmation date. Return / refund instructions are on the same order. Fully paid walk-in handovers use their separate payment-received completion rule; staff cannot submit an online customer rating or receipt.',
        ],
        [
            'question' => 'How do staff help a customer confirm an online order was received?',
            'previous_sha256' => '17ba535dc0c390020a6be00b440db3f306d674cc3ede0d6a09e8f1cea0f5a83a',
            'answer' => '1. Open the original order. It must be fully paid; verify Lalamove completion or mark the packed pickup ready before expecting customer receipt confirmation.
2. Ask the customer to sign in to their own account, open My Purchases and choose Order received for that order. They can also open it and scroll to Order received.
3. After checking the received clothing, the customer chooses a 1 to 5 star rating. The written review is optional. They tick I have received my order and choose Confirm Delivered. Staff cannot submit the customer\'s rating or receipt.
4. Check that Delivered and the customer confirmation date appear. Return / refund instructions are on the same order; courier completion alone does not close an online order.',
        ],
        [
            'question' => 'How do I ask for a refund or return?',
            'previous_sha256' => 'c887ebb4f8a7356f3d86a6f926ab0d97089c4a91bc39a755a15e9ed8e710c81d',
            'answer' => '1. Open your fully paid, Delivered order in My Purchases, or its private guest invoice. Online customers first complete Order received: choose a 1 to 5 star rating. The written review is optional. Tick I have received my order and choose Confirm Delivered.
2. Open Return / refund instructions or Request a refund or return on that order. The standard window is 7 days after confirmed receipt or walk-in handover; read the policy and deadline saved for your invoice. A late approval requires an owner-reviewed exception.
3. Enter your reason and upload the required private photo of the clothing or issue: JPG, JPEG, PNG or WebP, up to 3 MB and 6000 pixels per side. Send the request for review and follow the store return instructions.
4. The owner inspects returned clothing and reviews the eligible amount, including delivery where the saved policy allows. A request or approval is not money returned. Online refunds require provider confirmation; cash returns are recorded after cash is handed back. Check the result on the same order.',
        ],
        [
            'question' => 'Why do dashboard totals and charts show different numbers?',
            'previous_sha256' => 'df3ab441c2e50a10d0eef4dfc085de85746fb3efdd77a9b2bf20dbd283d7e893',
            'answer' => '1. Read each date label. With no sales dates selected, Annual Sales means January 1 through today in Asia/Manila for Confirmed Clothing sale, Paid orders, Average order value and Paid invoices after refunds. Customers counts registered customer accounts, not annual sales.
2. Both selected dates change the sales summaries and actual-sales chart to that range. Without dates, the chart keeps its recent Daily, Weekly, Monthly or Yearly window. The top Export sales uses annual summary dates by default; Reports Export payments uses chart dates.
3. Delivery and balances show the current position across all dates. This month\'s sales uses the current calendar month. Walk-in/online and Top 5 items graphs use the current month plus the previous 3 months.
4. Operations uses order placement dates; sales reports use confirmed payment dates. Forecasts use completed months. Different scopes are not automatically missing sales; compare like dates and measures.',
        ],
        [
            'question' => 'How do I check daily, weekly, monthly or yearly sales?',
            'previous_sha256' => '6bbaa543bb0a49267fc0a6754f7642da34d8acdd2c0ae10838c2fc2dd9628a8e',
            'answer' => '1. Open Reports on the dashboard or above Sales & Growth Forecast. Quick dates such as Today, This week or This month apply immediately.
2. Choose Daily, Weekly, Monthly or Yearly under Group sales by. For your own range, enter both From and To, then View sales. Weeks start Monday; dates use Asia/Manila. This year chooses Monthly automatically.
3. Without custom dates, the chart shows the latest 7 days, 8 weeks, 12 months or 5 years respectively. Its Total Sales, paid count, average order, exact amounts and Export payments share that chart range.
4. The dashboard\'s first sales summary cards separately default to Annual Sales, January 1 through today. Selecting both dates updates them too. Report dates do not retrain the completed-month forecast.',
        ],
        [
            'question' => 'How do staff read and export sales reports?',
            'previous_sha256' => '508b78383e2ab44e52a06bc2c1784e860eb9706711b314b19fe5077165a50844',
            'answer' => '1. Open Reports. Choose Quick dates, or Group sales by and both From/To dates, then View sales. Dates use Asia/Manila.
2. Read Total Sales and its chart: fully paid clothing after discounts, before refunds, excluding delivery. View exact sales amounts opens the matching table.
3. Reports Export payments uses chart dates. The dashboard\'s top Export sales uses Annual Sales, January 1 through today, unless dates are selected. The CSV separates delivery, confirmed refunds, original invoice and net invoice; net invoice includes delivery.
4. Active visible reports check for recorded changes every 15 seconds; Refresh data checks immediately. Forecasts use completed months. Operations uses order placement dates, so counts can differ from paid-date sales.',
        ],
        [
            'question' => 'What do Critical low and Alert mean in inventory?',
            'previous_sha256' => '2e7725577541edcf1c7258733ffd579ae7824e414ac6b23541fe80dcae3f9716',
            'answer' => '1. Read the lower Inventory attention panel; the owner also has Notifications in the workspace header. Open View all shortages or View affected variants for each size/color. Available pieces = counted stock minus reserved pieces.
2. Red Critical low means at or below half the variant\'s reorder threshold, rounded down. Zero stock is critical. Yellow Alert means above that level but at or below the threshold.
3. Counts cover all affected active, non-archived size/color variants; the dashboard previews the six most urgent. Low-stock variants opens the full list. The separate large top warning banner has been removed.
4. For threshold 10, 0 to 5 available is Critical low and 6 to 10 is Alert. Ask the owner to confirm physical stock and restocking needs. A badge does not purchase clothing or change stock automatically.',
        ],
        [
            'question' => 'Where can the owner see low-stock notifications?',
            'previous_sha256' => '7c81e8f6a5f2fad60a6aca91a0e244fbc09d87bfdd40a9b4fe3a4c70d68f47ee',
            'answer' => '1. Open Notifications in the workspace header. Its badge counts affected size/color variants of active products, not unread messages or total products.
2. Red Critical low means critically low or zero available stock; yellow Alert means low stock. Choose View affected variants, or use the lower Inventory attention panel and Low-stock variants dashboard count.
3. The header menu updates when you open or reload a workspace page; opening it does not clear warnings. The lower dashboard panel uses the normal active report refresh. The duplicate large top warning banner has been removed.
4. Check sizes, colors and physical counts before editing stock or purchasing. Alerts do not automatically restock or email anyone. A recovered variant\'s warning disappears on the next applicable refresh.',
        ],
        [
            'question' => 'What should I check first on the dashboard?',
            'previous_sha256' => '7aeb79163d6e9a0e17a595671833203b20394919e3049abbe84647cc3c428a4d',
            'answer' => '1. Check Needs attention: For packaging, Ready for delivery, Returns to review and Low-stock variants. Open a count for its related records.
2. Read Confirmed Clothing sale, Paid orders and Average order value. Their default Annual Sales scope is January 1 through today in Asia/Manila. Customers counts registered accounts. Both selected dates override the sales summary scope.
3. Use Reports for date-specific Total Sales and its graph. Walk-in & online orders and Top 5 most bought items cover the current month and previous 3 months, so they can differ from annual cards.
4. Use the lower Inventory attention panel and the owner\'s Notifications bell for stock warnings. Paid invoices after refunds remains at the bottom. Redundant delivery-method and forecast shortcut panels and the large warning banner were removed.
5. Open Sales & Growth Forecast from the workspace menu for planning estimates. Staff and owners use only actions permitted for their signed-in role.',
        ],
        [
            'question' => 'How does the owner add a product and its size and color variants?',
            'previous_sha256' => '5493e8156bd460814fb140a4ca2135c672b41c9e3af3ff66bcba923559e1e473',
            'answer' => '1. Open Products & inventory, choose Add product or edit an existing product, then save its details, photo and confirmed size chart.
2. Under Sizes & colors, use Add a variant for one sellable size/color combination. Enter its actual color, prices, counted stock and reorder threshold. White is a normal color; no White/Colored group selection is needed. The SKU is automatic.
3. If several sizes share an existing color\'s sizes and prices, expand Add one color in all existing sizes. Enter the new color and choose Sizes and prices to use. Review the visible prices before Create size variants.
4. Review prices; new color variants start at zero stock. Enter counted quantities before selling. To remove an uploaded photo, tick Remove current photo and save that product or variant form. A default preview can remain visible.',
        ],
        [
            'question' => 'How do I add White or another color to a product?',
            'previous_sha256' => '81d28ca14eb789a61cad283a97fb00396de0235f284febb88d43ba8b5f011bee',
            'answer' => '1. Open Products & inventory and edit the product. White is a normal color, with no separate White/Colored group.
2. For one combination, use Add a variant. Enter the size, actual color, prices, counted stock and reorder threshold, then Add variant.
3. For multiple sizes with matching prices, expand optional Add one color in all existing sizes. Type the new color and choose Sizes and prices to use. Review the visible Re-seller, Wholesale and Retail / SRP prices before Create size variants.
4. Choose the source carefully where colors have different prices. Every new size starts with zero stock. Review saved prices and enter physical counted quantities before selling; copying prices does not create stock.',
        ],
        [
            'question' => 'How do staff read the two forecast charts?',
            'previous_sha256' => 'd89872afa358361ab5486faea2d717d3126e64f2b20026ea74a5dfd6703f7937',
            'answer' => '1. Open Sales & Growth Forecast from the workspace menu. The solid line shows confirmed clothing sales for completed months; the dashed line leads to the shaded forecast month.
2. Historical growth carries the latest two-month change forward. Sales forecast fits a straight trend across the eligible history, up to six completed months.
3. Read the two-decimal estimate and View exact chart amounts. Both charts use the same peso scale. Swipe on a narrow screen to see every month.
4. Historical Error panels are no longer shown. Check completed months and calculation inputs instead. Needs history does not mean zero demand, and estimates do not guarantee future sales.',
        ],
        [
            'question' => 'Why can the two forecasts disagree after a low-sales month?',
            'previous_sha256' => '50d55ee587e9c72ecb18ba28f938deb31052c810a37a563d635dd84fc0cdaab2',
            'answer' => '1. Historical growth follows the last two eligible completed months. A low latest month can produce a sharp fall and small next estimate.
2. Regression fits a straight trend across wider eligible history, up to six completed months. It can differ from the latest two-month change.
3. Expand exact monthly amounts and history/calculation inputs. Check whether a low month is genuine recorded sales or incomplete demonstration data before planning stock. Historical Error panels are not displayed.
4. Neither estimate guarantees sales. Do not invent sales or alter old payments to improve a chart. Simulated history demonstrates formulas; real evaluation needs genuine later sales not used to fit the forecast.',
        ],
        [
            'question' => 'Can simulated sales prove that our forecasts are accurate?',
            'previous_sha256' => '52998a319369b9e9dad1851d41a384389b87feed0ef47585c65066b9fb1ea67d',
            'answer' => '1. Simulated payments demonstrate formulas, charts and order workflows. Label them as demonstration data and keep them separate from genuine business records.
2. More simulated history can produce an estimate, but cannot establish accuracy for the real store. A clear chart is not proof that future sales will match.
3. Do not add or alter sales to improve an apparent result. Evaluate forecasts against later genuine sales unavailable when the estimate was made.
4. Record the period, actual outcomes, sample size and limitations for the owner or panel. The simplified forecast page does not display Historical Error panels or promise a fixed accuracy percentage.',
        ],
    ];

    public const DEACTIVATE = [
        [
            'question' => 'What do forecast history and accuracy warnings mean?',
            'previous_sha256' => 'caca72a9eff68d976e007acffa4ebcba1dbfe3faafbf2ef4a14f3e6a16013b8e',
            'audience' => 'staff',
            'previous_keywords_sha256' => '01f472e094bdcdcbe0d5d60004990517648e104a064a83f6efa828e4de1bbf25',
            'sort_order' => 500,
        ],
        [
            'question' => 'What do Average miss and Relative error mean in historical error?',
            'previous_sha256' => '98bf39397ac414a3ba6f1a86200443bf08af3b0dce1fc8149ffd0910ea62a567',
            'audience' => 'staff',
            'previous_keywords_sha256' => '41d649fc063529a1bdc7be5d565d95dff007873889f4fef546c7d42f8e8e5905',
            'sort_order' => 730,
        ],
        [
            'question' => 'Why does regression historical error need at least five completed months?',
            'previous_sha256' => '351876cc4c2c19b56598b3bca46c4b7dfa148c06baeb6e7298712e3cd4e512b2',
            'audience' => 'staff',
            'previous_keywords_sha256' => '845412b9fce1a6f2fc6ead32503444f64fda09bc778d4aed21d31dfe573e37bb',
            'sort_order' => 740,
        ],
    ];

    public const ADDITIONS = [
        [
            'audience' => 'staff',
            'question' => 'Why does a forecast show Needs history?',
            'answer' => '1. Open Sales & Growth Forecast and check completed months and exact inputs. The current incomplete month is excluded.
2. Growth needs at least 2 completed months and a positive earlier baseline. Regression needs at least 3 completed months. Missing history does not mean zero demand.
3. A product starts in its catalog creation month or an earlier verified paid-sale month for imported history. Earlier nonexistent months are excluded; eligible zero-sale months remain included.
4. The page uses up to six completed months and combines each product\'s sizes/colors. Treat estimates as planning help, check actual stock and evaluate later genuine sales.',
            'keywords' => 'needs history, insufficient forecast history, missing forecast estimate, zero demand not assumed',
            'sort_order' => 1040,
        ],
        [
            'audience' => 'owner',
            'question' => 'How do I filter All, Low inventory or Hidden products?',
            'answer' => '1. Open Products & inventory on Current products. Under Show products, choose All products, Low inventory or Hidden products, then Apply filters. Search products works with that selection.
2. All products includes current active and hidden products. Low inventory includes products with an active size/color at or below its reorder threshold, including zero stock; hidden products are included and labeled.
3. Hidden products shows items switched off for the storefront. Archived products is a separate tab and is excluded from current-product filters.
4. Clear filters resets the list. Filtering only changes the display; it does not edit stock, hide products or archive them.',
            'keywords' => 'product filters, show all products, low inventory products, hidden products filter, catalog filters',
            'sort_order' => 1041,
        ],
        [
            'audience' => 'owner',
            'question' => 'How do I remove a product or variant photo?',
            'answer' => '1. Open Products & inventory and edit the product. For a variant photo, choose that exact size/color\'s Edit action instead.
2. Tick Remove current photo in the uploaded photo area, then save the matching product or variant form. Leaving without saving does not apply removal.
3. A default preview may remain visible. Removing an upload does not delete the product, variant, prices or stock.
4. To replace a photo, choose a permitted image and save the matching form. Review its saved image and color. Generated demo previews are examples, not supplier photographs.',
            'keywords' => 'remove product photo, remove variant image, delete uploaded photo, default preview photo, change product picture',
            'sort_order' => 1042,
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::REFRESH as $change) {
                $faq = DB::table('chatbot_faqs')->where('question', $change['question'])->lockForUpdate()->first();
                if ($faq && $faq->question === $change['question']
                    && hash_equals($change['previous_sha256'], $this->digest($faq->answer))) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update([
                        'answer' => $change['answer'], 'updated_at' => now(),
                    ]);
                }
            }
            foreach (self::DEACTIVATE as $change) {
                $faq = DB::table('chatbot_faqs')->where('question', $change['question'])->lockForUpdate()->first();
                if ($faq && $faq->question === $change['question'] && $faq->active
                    && $faq->audience === $change['audience'] && (int) $faq->sort_order === $change['sort_order']
                    && hash_equals($change['previous_sha256'], $this->digest($faq->answer))
                    && hash_equals($change['previous_keywords_sha256'], $this->digest($faq->keywords ?? ''))) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update(['active' => false, 'updated_at' => now()]);
                }
            }
            foreach (self::ADDITIONS as $row) {
                DB::table('chatbot_faqs')->insertOrIgnore($row + [
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function digest(string $value): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $value));
    }

    public function down(): void
    {
        // Keep corrected guidance, retired defaults and subsequent owner edits.
    }
};
