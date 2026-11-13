# Major revision acceptance checklist

This checklist covers the user's 17-item revision and the simpler staff/owner dashboard. Shopee screenshots supplied by the owner were used as layout and workflow references; the store keeps its own branding, pricing rules, roles and verified payment workflow.

| Item | Implemented result |
|---|---|
| 1. Full payment | New online and walk-in purchases reject installment plans and partial amounts. Older payment records remain intact and may settle their complete outstanding balance on the original invoice. |
| 2. Required delivery pin | Lalamove quotes and checkout require valid coordinates plus customer confirmation of the entrance. GPS permission is optional; map selection and known coordinates remain available. |
| 3. Terms | Manual registration, new Google customer accounts and checkout require Terms acceptance, recorded with date/version. Store Terms link the official payment and courier policies. |
| 4. Payment flow and contacts | Customer mobile fields accept exactly 11 digits beginning with 09. Email format and common domain typos are validated; OTP or verified Google identity establishes inbox ownership. Checkout progress now advances from details to review to secure payment; only stored full-payment confirmation completes it. |
| 5. Required delivery fields | Required address/contact inputs and the pin confirmation show stars. Labels now keep each star beside its field name on desktop, phone and tablet. |
| 6. Clothing images | Approved photorealistic demo previews replace sketches across the banner and current catalog styles/colors, including separate adult/kids necklines. Owner-uploaded actual photographs take priority. Demo images do not establish exact supplier fabric or shade. |
| 7. Lalamove instructions | The staff demonstration guide, Sandbox order note and approved staff FAQ library describe packing, ready status, booking, reconciliation and customer receipt confirmation. No real courier should be dispatched for a demonstration. |
| 8. Clean forecasts | Financial estimates, rates, intercept/slope and historical error values use two decimal places. Whole-piece demand/reorder quantities remain whole pieces. Forecasts use completed calendar months; sales reports provide daily, weekly, monthly and yearly grouping. |
| 9. Archive then delete | Owner tools archive and restore products/variants. Permanent deletion requires an archived item, reason and explicit confirmation, and is blocked by protected transaction/reservation references. |
| 10. Size chart | Compact measurements appear beside pricing on larger screens and stack on phones. Existing confirmed adult/polo/kids references remain in the catalog. |
| 11. Sizes | New adult variants use XS, S, M, L, XL, 2XL, 3XL, 4XL and 5XL. Actual existing kids, TN, Free Size and legacy supplier labels remain usable without rewriting old orders. |
| 12. To Ship | Customer/staff displays use To Ship in place of With courier. Provider completion alone does not invent customer receipt confirmation. |
| 13. Address book | Customers add/edit/delete addresses, choose a default and select a saved address at checkout. Ownership checks prevent using another customer's address; destination changes invalidate the quotation. |
| 14. Persistent cart | Customer carts persist in the database across logout/browser exit, with separate guest persistence and customer isolation. Checkout consumes ordered quantities atomically. Temporarily archived catalog choices may remain stored while unavailable. |
| 15. Simple dashboard | Needs attention counts and compact sales cards lead to clear sales/channel charts. Walk-in checkout versus Online orders and the Top 5 product trend use the latest four calendar months, including the incomplete current month. |
| 16. My Purchases | Compact order table, status tabs, search, pagination, totals, payment records and delivery links replace large purchase cards. Released/closed legacy invoices no longer also appear in active fulfillment tabs. |
| 17. Reports | Date range and daily/weekly/monthly/yearly grouping, readable axes, exact-value tables, two-decimal amounts and CSV exports are available. Confirmed refunds remain separately identifiable rather than silently rewriting original paid sales. |

## Verification for this follow-up

- Full isolated feature suite: **1,771 tests, 15,656 assertions passed** on PHP 8.4.24. This includes the new checkout-progress and released-invoice regressions.
- Browser preview: invalid contact details stay at Details; valid pickup details reach Review; Lalamove selection requires a confirmed pin and fresh fee; a valid simulated PHP 44 quote reaches Review with the correct PHP 515 total; changing the address clears the quote and resets progress. A stored fully paid invoice renders Order confirmation.
- Pending/creating/unknown/failed payment attempts cannot advance the stored invoice to confirmed merely through return-URL parameters. Guest online invoice progress and cash/closed exclusions are covered by focused tests.
- Phone and tablet layouts were inspected using isolated data. No real payment, email, courier booking, account/password change or new demo sale was performed in this follow-up.
- The source-only publication package contains eight runtime files. It has no migration, dependency update, seed/import, provider setting or database-write command. Hosted publication is verified separately by its private completion record.

Official policy references checked: [PayMongo refund process](https://docs.paymongo.com/docs/payment-acceptance-refunds), [Lalamove Philippines Terms](https://www.lalamove.com/en-ph/terms-and-conditions). The website links the providers' current policies rather than claiming to control their cancellation decisions or processing times.

Participant usability scores, actual-device survey results and forecast accuracy with genuine future store sales remain separate research evidence. The previously deferred five study-alignment items are not silently included in this revision.
