# Understanding the current forecasts

The system estimates the current calendar month using up to six completed months. For example, on 17 October, it uses completed months through September to estimate October. It does not compare an unfinished October with a complete September.

Sales means clothing subtotals from fully paid orders, after quantity discounts. Delivery fees and pending/partial payments are excluded. Refunds are recorded separately and are not automatically subtracted from these clothing totals because clothing/delivery refund allocation has not been recorded.

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

The calculation inputs are visible under Sales & Growth Forecast → View historical periods and calculation inputs, and under each product's history details. Estimates need historical backtesting and do not guarantee future sales.
