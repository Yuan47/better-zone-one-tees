# Understanding the current forecasts

The system estimates the current calendar month using up to six completed months. For example, on 17 October, it uses completed months through September to estimate October. It does not compare an unfinished October with a complete September.

Sales means **confirmed clothing sales before refunds**: clothing subtotals from fully paid orders, after quantity discounts. Delivery fees and pending/partial payments are excluded. Demand means **originally ordered paid pieces before returns**. New return inspections record returned quantities and clothing/delivery refund allocations; historical refunds may lack those details. The forecasts deliberately retain original paid sales and pieces before refunds or returns, rather than combining inconsistent historical return records. Refunds are reported separately. A refund amount alone is never converted into returned product quantities. The refund-adjusted invoice report includes delivery and is a different measure from this clothing forecast.

## Historical growth

This method assumes the latest monthly growth continues.

**Growth rate = (last completed month − month before) ÷ month before**

**Forecast = last completed month × (1 + growth rate)**

If August sales were ₱12,000 and September sales were ₱14,000:

- Growth rate = (14,000 − 12,000) ÷ 12,000 = 0.1667, or about 16.67%.
- October forecast = 14,000 × (1 + 0.1667) ≈ **₱16,333.33**.

It needs at least two completed months and a positive earlier month. If the earlier month is zero, a percentage increase cannot be calculated, so the system shows Needs history.

## Linear regression

This method fits a straight trend line to the available completed months.

**y = a + bx**

| Symbol | Meaning |
| --- | --- |
| y | Forecast sales in pesos, or product demand in pieces |
| x | Chronological month number: 1, 2, 3… |
| a | Intercept: the fitted line's value at month 0; a calculation anchor, not an actual recorded sale |
| b | Slope: the estimated change for each additional month |

For July ₱10,000, August ₱12,000 and September ₱14,000, assign x = 1, 2 and 3. The fitted values are a = ₱8,000 and b = ₱2,000 per month. October is x = 4:

**y = 8,000 + (2,000 × 4) = ₱16,000**

The system calculates these coefficients from the historical data:

**b = Σ[(x − average x)(y − average y)] ÷ Σ[(x − average x)²]**

**a = average y − b × average x**

Regression needs at least three completed months. Negative predictions are displayed as zero. The growth and regression estimates are separate alternatives; they are not added together or automatically averaged.

## Product demand and restocking

The same methods can estimate pieces instead of pesos. Each product combines all of its sizes and colors.

**Historical units = total paid pieces sold during the eligible completed months**

Product history begins in the month the product entered the catalog, or an earlier verified paid-sale month when importing history. Months before that are excluded. Zero-sale months inside its eligible history are included. A product without completed-month paid sales needs history before demand is estimated.

**Available stock = stock on hand − reserved pieces**

**Suggested reorder = max(0, rounded-up regression demand − available stock)**

For a forecast of 100 pieces with 70 available, suggested reorder is **30 pieces**. This compares the full target month's estimate with current availability. It does not subtract this month's sales or include incoming purchases, supplier lead times, safety stock, seasonality, or a size/color purchasing mix. It is a planning suggestion and does not place a purchase order.

## Measuring forecast error

The forecast page now compares past estimates with actual completed months. This is a chronological backtest: to predict September, it uses only the eligible months before September. September and later sales never enter that test's training data. The next month is then tested using its own earlier history. This avoids measuring a model against the same values used to fit it.

Historical growth needs two earlier months and a positive earlier baseline for each test. Regression needs three earlier months. The page requires **at least two usable held-out test months for a method** before showing its error measures. With a continuous series, this is at least four completed months for growth and five for regression; zero baselines can leave growth with fewer usable tests. An estimate can be available before its historical error can be measured.

**Average miss (MAE) = Σ|forecast − actual| ÷ number of test months**

**Relative error (WAPE) = Σ|forecast − actual| ÷ Σ|actual| × 100**

MAE is expressed in pesos for clothing sales and pieces for product demand. WAPE is a percentage **error**, not a guaranteed accuracy score. It can exceed 100%. If actual test sales total zero, WAPE is unavailable rather than reported as 0% or 100% accuracy. Demand tests use predictions rounded up to whole pieces, matching the displayed demand estimates. Negative regression predictions are clamped to zero before testing, matching the actual forecast behavior.

The page shows how many months were tested. With the six-month history window, the evidence is necessarily small: at most four growth tests and three regression tests. It cannot establish seasonality, future accuracy, or a defensible fixed accuracy percentage. The figures describe measured historical error; evaluate future forecasts against actual outcomes as new months finish. No sales history produces **Needs history**, not a fabricated zero-demand forecast or perfect accuracy result.

Product tests also require a paid sale known before the test month. Months before a product's first paid sale do not contribute invented zero forecasts or dilute the measured error. A verified sale before the six-month window still establishes prior observed history, even if recent eligible months have zero sales.

The calculation inputs are visible under Sales & Growth Forecast → View historical periods and calculation inputs, and under each product's history details. Revenue backtest estimates and actuals are available under View forecast error formulas and past estimates. Estimates do not guarantee future sales.
