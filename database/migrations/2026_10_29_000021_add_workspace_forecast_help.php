<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['How do staff read the two forecast charts?',
                "1. Open Sales & Growth Forecast. The solid line shows actual confirmed clothing sales for completed months; the dashed line leads to the shaded forecast month.\n2. Historical growth carries the change between the last two completed months forward. Sales forecast uses the overall trend across the eligible months.\n3. Read the estimate above each chart and expand View exact chart amounts for the values. Both charts use the same peso scale. On a narrow screen, swipe the chart to see every month.\n4. A forecast is an estimate, not a paid order or a promise. Read the history and error warnings before using it to plan stock.",
                'read forecast charts, solid line, dashed line, shaded forecast month, understand forecast graph'],
            ['How is the historical growth forecast calculated?',
                "1. Find the last two completed months of confirmed clothing sales. The current incomplete month is excluded.\n2. Growth rate = (last month - month before) / month before. For example, a change from PHP 10,000 to PHP 12,000 is 20% growth.\n3. Forecast = last month × (1 + growth rate). In that example, PHP 12,000 × 1.20 = PHP 14,400. Falling sales can produce a lower estimate.\n4. Growth needs at least 2 completed months and a positive earlier baseline. A zero earlier month means the rate cannot be calculated; it does not prove future demand is zero.",
                'historical growth formula, calculate historical growth, growth rate example, last month growth'],
            ['What do intercept, slope, and x mean in the sales forecast?',
                "1. Sales forecast uses y = a + bx. Each completed month is numbered in time order: x = 1, 2, 3, and so on. The target month is the next number.\n2. Intercept a anchors the fitted line at month 0. It is a calculation value, not an actual recorded sale.\n3. Slope b is the estimated average change for one more month. Positive means the fitted trend rises; negative means it falls. Revenue uses pesos; product demand uses pieces.\n4. For example, a = PHP 1,000 and b = PHP 200 gives PHP 2,000 at x = 5. The fitted forecast needs at least 3 completed months; a negative final estimate is displayed as zero.",
                'intercept and slope, what is slope, what is intercept, explain a and b, forecast x month number, linear regression example'],
            ['What do Average miss and Relative error mean in historical error?',
                "1. Historical error checks an earlier month by forecasting it using only the months before it, then comparing the forecast with that month's actual sales. Later sales are never used to predict that earlier month.\n2. Average miss (MAE) = total absolute differences / number of test months. It shows the typical miss in pesos for sales, or pieces for product demand.\n3. Relative error (WAPE) = total absolute differences / total actual sales × 100. If two forecasts miss by PHP 100 and PHP 300, MAE is PHP 200. If those months actually sold PHP 4,000 in total, WAPE is 10% error.\n4. Lower error is better, but 10% error is not a guaranteed 90% accuracy score. WAPE is unavailable when actual sales total zero. At least 2 usable test months are required to show these measures.",
                'average miss, relative error, mae explained, wape explained, historical error example, forecast error percentage'],
            ['Why does regression historical error need at least five completed months?',
                "1. A regression forecast needs at least 3 earlier completed months to fit its first trend line.\n2. Month 4 can then be the first test month: forecast it using months 1 to 3, then compare with its actual sales. One test is not enough for the error summary.\n3. Month 5 becomes a second test using months 1 to 4. Therefore at least 5 eligible completed months are needed for 2 regression test months. Growth can have 2 tests after 4 months if both earlier baselines are positive.\n4. The page uses up to 6 completed months, so 6 eligible months can provide 3 regression tests and 4 growth tests. A small sample is preliminary evidence; missing or zero-baseline history can reduce the usable tests.",
                'five completed months, regression needs history, not enough test months, minimum regression history, why accuracy not measured, two regression test months'],
            ['Which sales months are included in the forecast history?',
                "1. Forecasts use up to the latest 6 completed calendar months of fully paid orders. The current incomplete month is excluded even if it already has sales.\n2. A product's history starts in its catalog creation month, or an earlier recorded paid-sale month for imported history. Months before the product existed are excluded.\n3. Zero-sale months inside that eligible history are included. A product with no paid pieces in a completed month shows Needs history instead of assuming zero demand.\n4. Expand a product's history to check its periods and paid pieces. Products can have different history lengths, so compare their estimates with that context.",
                'eligible forecast months, current month excluded, product history starts, new product forecast, zero sale months'],
            ['What do Historical units and Available mean in the product demand table?',
                "1. Historical units is the total originally ordered, fully paid pieces across that product's eligible completed months, combining its sizes and colors. It is a total across the history, not one month's average.\n2. Growth forecast and Regression forecast estimate pieces for the target month. Product estimates round up to whole pieces.\n3. Available is current stock minus pieces reserved for unpaid orders. It combines all variants of the product and can change while the historical periods stay the same.\n4. Staff can review Inventory attention and ask the owner to check individual sizes and colors in Products & inventory. A high product total does not mean every size or color has enough available stock.",
                'historical units meaning, available forecast column, paid pieces history, reserved stock forecast, product demand columns'],
            ['How should staff use Suggested reorder?',
                "1. Suggested reorder = max(0, rounded-up regression demand - available stock). For example, 120 forecast pieces minus 80 available gives 40 suggested pieces.\n2. Zero means current available stock covers that estimate. A dash or Needs history means an estimate is unavailable; it is not proof that nothing needs ordering.\n3. The suggestion compares the full target month with current stock. It does not subtract this month's sales or include incoming purchases, supplier lead times, safety stock, or seasonal events.\n4. Check individual sizes and colors, actual stock, and supplier timing with the owner before purchasing. Suggested reorder is planning help and never creates a purchase or changes inventory automatically.",
                'suggested reorder meaning, suggested reorder example, reorder limitations, zero suggested reorder, how much to reorder'],
            ['How do refunds and delivery fees affect forecast sales?',
                "1. Forecast sales use the original confirmed clothing subtotal of fully paid orders. Delivery fees are excluded.\n2. Refunds are reported separately; the forecast deliberately keeps original clothing sales before refunds. Product demand likewise uses originally ordered paid pieces before returns.\n3. For example, PHP 1,000 clothing plus PHP 100 delivery contributes PHP 1,000 to forecast sales. A later refund does not turn that forecast history into net sales or pieces kept by the customer.\n4. Use the sales and refund reports together when reviewing money retained after refunds. Do not describe these forecast figures as profit or net sales after refunds.",
                'refund effect on forecast, refunds and forecasts, delivery fees excluded forecast, forecast sales basis, forecast before refunds'],
            ['How do staff sort the forecast tables?',
                "1. Click a sortable number heading once for Highest to Lowest, again for Lowest to Highest, and a third time for Default. Text headings use A to Z, then Z to A, then Default. Dates use newest first, then oldest first, then Default.\n2. The heading shows the active direction. Default restores the table's original order; sorting a different column starts that column's cycle.\n3. Missing values stay after values that can be compared. On a paginated table, sorting applies to all matching rows, not only the current page. Use Enter to activate a heading link; heading buttons also support Space.\n4. Sorting changes only how rows are displayed. It does not edit sales, change the chart, reorder the months used by a formula, or recalculate a forecast from the displayed row order.",
                'sort forecast table, highest to lowest, lowest to highest, default table order, clickable table heading'],
            ['Can simulated sales prove that our forecasts are accurate?',
                "1. Simulated payments demonstrate how the formulas, charts, and historical error measures behave. They must be labeled as demonstration data.\n2. A longer simulated history can make error measures available, but its error describes that artificial example only. It does not establish real store accuracy.\n3. Do not add or change old sales just to improve the error score. For a real evaluation, compare forecasts with later genuine sales that were not used to fit the forecast.\n4. Keep demonstration data separate from real business records and evaluate several real periods. Record the sample size and limitations when presenting results to the owner or research panel.",
                'simulated sales accuracy, demo forecast accuracy, synthetic data historical error, artificial sales history, forecast demonstration limitations'],
        ];

        foreach ($rows as $index => [$question, $answer, $keywords]) {
            // Existing questions may contain approved edits, a custom audience, or an intentional inactive state.
            DB::table('chatbot_faqs')->insertOrIgnore([
                'question' => $question, 'answer' => $answer, 'keywords' => $keywords,
                'audience' => 'staff', 'active' => true, 'sort_order' => 700 + $index * 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain owner-editable help and its audience boundary when reverting code.
    }
};
