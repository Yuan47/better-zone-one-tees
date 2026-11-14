# Live sales reports and forecast overview

The staff/owner dashboard and Sales & Growth Forecast page now share a simple actual-sales report. The owner's supplied PayMongo screenshot was used as a layout reference; the figures come from Zone One Tee's verified store records.

## Reading the report

1. Open **Reports** in the dashboard, or open **Sales & Growth Forecast**.
2. Use **Quick dates** for Today, Yesterday, Last 7 days, Last 30 days, This week, This month or This year. These shortcuts apply dates immediately. This year uses monthly grouping to keep the chart readable.
3. Alternatively choose **Daily, Weekly, Monthly or Yearly**, fill both dates, and select **View sales**. Weeks begin Monday. Dates use Asia/Manila.
4. Read **Total Sales**, paid-order count and average order value, then the **Sales over time** graph. Open **View exact sales amounts** for the underlying period totals.
5. **Export payments** downloads a CSV of paid invoices for the same dates shown by the chart. Delivery and confirmed refunds remain separate export fields.

Total Sales adds fully paid clothing subtotals after quantity discounts, before refunds and without delivery charges. It includes confirmed online and recorded walk-in cash sales. Pending invoices and browser payment-return parameters do not count as confirmed sales. A new payment is reflected only after the existing trusted payment workflow records it.

With no dates selected, the chart retains its established recent window: 7 days, 8 weeks, 12 months or 5 years. Dashboard top sales cards retain their clearly labelled all-time scope until explicit dates are selected. Current delivery/balance cards, this-month sales and four-month product/channel graphs retain their own stated scope.

## Automatic updates

- The open page checks the authenticated server every 15 seconds while visible, online and active. **Refresh data** provides a manual check. The last-checked time is displayed.
- The figures, charts and related dashboard sections update together. Open calculation details, table sorting and horizontal scrolling are retained.
- Editing dates or grouping pauses automatic refresh until **View sales** applies the new selection. This prevents old-period updates from interrupting a filter change.
- Hidden/offline pages pause. Automatic checks also pause after 15 minutes without real keyboard, pointer or scroll activity, and resume on interaction. This does not replace or change account/session security rules.
- A connection failure, HTML error response or unavailable server keeps the last loaded figures and shows a short retry message. Invalid HTML is never displayed as a raw JSON parsing error.
- An expired session or denied workspace role clears the private report figures, stops refreshing and provides sign-in guidance. Every refresh uses the same authentication, email-verification and staff/owner access checks as the original page.

This is near-live polling, rather than a direct payment-provider stream. A provider must first confirm a payment to the system; an active page then ordinarily displays the saved change on its next successful check. A slow connection or provider delay can extend that interval.

## Forecasts remain clearly separate

Total Sales and the actual-sales chart appear above the existing forecast charts. The selected report dates filter actual sales; they do not redefine the forecasting model's training history.

Historical growth and linear regression retain up to six completed calendar months. A new payment in the current month updates actual sales and current inventory/reorder information, while the monthly sales estimate continues to use completed history. This avoids feeding an unfinished month into a completed-month trend. Forecast charts distinguish actual paid sales from the estimated period, show two-decimal money values and retain the insufficient-history messages and historical-error checks.

## Verification

The isolated integration cases cover staff/owner access, guest/customer/unverified denial, private non-cached responses, invalid dates/grouping, new trusted online confirmations, recorded walk-in cash receipts, duplicate reads without duplicate stock deduction, shipping exclusion and unchanged completed-month forecasts after a current-month payment.

Browser checks additionally verify automatic sales updates without a page reload, retained open details, readable failure/recovery, locking after denied access, shortcut/grouping behavior, phone/tablet layouts and sorting after a changed forecast snapshot. All browser transaction fixtures use the separate private preview database; no real payment or courier action is performed for these checks. The final test and hosted publication evidence are saved separately with the release.

Release verification on 14 November 2026: **1,833 feature tests and 16,179 assertions passed**, with no failures, errors or skipped tests. The focused report/refresh set passed 57 cases and 480 assertions. These counts include controlled provider confirmations and do not claim a new real-wallet payment or actual courier dispatch.
