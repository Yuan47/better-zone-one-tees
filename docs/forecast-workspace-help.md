# Forecast help for staff and the owner

The Staff assistant uses approved forecast guides for both staff and owner accounts. These guides have the **Staff** audience, so the customer assistant cannot access them. The owner can review, edit, deactivate, or delete them in the Approved FAQ library.

Ask a question such as:

- “How do staff read the two forecast charts?”
- “What do Average miss and Relative error mean in historical error?”
- “Why does regression historical error need at least five completed months?”
- “What do Historical units and Available mean in the product demand table?”
- “How should staff use Suggested reorder?”
- “How do staff sort the forecast tables?”
- “Can simulated sales prove that our forecasts are accurate?”

The guides also cover the growth formula, intercept and slope, eligible periods, zero-sales baselines, refunds, and delivery fees. They are returned directly as approved answers even when the AI connection is unavailable.

## Reading historical error

Historical error asks: **if we had forecast an earlier month using only the history available then, how far would we have missed?**

**Average miss (MAE)** is the average absolute difference between forecasts and actual sales. A miss of PHP 100 in one month and PHP 300 in another gives an average miss of PHP 200. Product demand uses pieces instead of pesos.

**Relative error (WAPE)** compares the total absolute differences with total actual sales. If those test months actually sold PHP 4,000, the PHP 400 total miss is 10% error. It is not a promise of 90% accuracy. Lower error is better; when actual sales total zero, WAPE cannot be calculated.

Regression needs at least three training months. The fourth month provides one test; the fifth provides the second. Therefore at least five eligible completed months are needed for the regression error summary. Six months provide up to three regression tests and four growth tests. The page uses a maximum of six completed months, excluding the current incomplete month.

Simulated payments make these calculations demonstrable. They do not establish real business accuracy. Existing historical sales must never be changed to produce a better score.

## Data and inventory meaning

Sales forecasts use the original confirmed clothing subtotal of fully paid orders before refunds, excluding delivery fees. Demand uses originally ordered paid pieces before returns. Historical units totals all eligible periods; available stock is current on-hand stock minus reservations. Product totals combine sizes and colors.

Suggested reorder compares rounded-up regression demand with available stock. It is planning help, not an automatic purchase, and omits lead times, incoming stock, safety stock, seasonal changes, and individual variant needs. Staff should review these with the owner.

Sorting table rows changes only their display order. Charts and calculations always keep the actual historical periods in chronological order.

## Updating help safely

The additive migration inserts only missing questions. Re-running it or rolling back its code retains existing owner edits, inactive answers, custom audiences, and their order. It does not rewrite any existing forecast formula or sales record.
