# Reliability and usability troubleshooting

This pass tested customer, staff and owner workflows using isolated data and controlled service failures. It builds on the prior 17-item revision acceptance; it is not a participant usability survey or a guarantee that an external provider can never fail.

## Problems reproduced and fixed

| Problem | Result after correction |
|---|---|
| Malformed profile username input could cause an error or be silently converted. | Only string usernames are normalized; wrong shapes are rejected without changing the account. |
| Rejected form data could crash the page when it was shown again. | Shared display helpers keep ordinary entered values and safely display invalid scalar/row shapes in registration, account, address, product, walk-in, FAQ, settings, staff and other forms. Server validation still rejects the original invalid submission. |
| Adding beyond the per-variant cart limit could erase or silently reduce quantities. | The request is rejected with a clear message, and previously saved quantities remain intact, including controlled overlapping browser additions. A rejected addition also no longer records a misleading completed action in the owner's activity log. |
| Malformed search/filter URL arrays could crash the storefront, sign-in header or owner product list. | Invalid shapes are ignored by read-only search controls; normal search and submitted-form validation remain unchanged. |
| Inventory search ignored the literal search term `0`. | The same search/filter now treats zero as an actual search term. |
| An invalid provider checkout reference could leave an unusable pending payment. | Invalid creation responses remain uncertain and available for owner reconciliation instead of saving a reference that the retrieval flow cannot use. Valid documented mixed-case references and the already-supported boundary remain accepted. |
| Legacy payment confirmation could reuse another invoice's recorded payment. | Both callback and reconciliation paths check previously used receipt/order references before applying funds. Wrong confirmations leave the original payment, invoice and stock unchanged. |
| The assistant invited unverified customers to send messages that verification rules would block. | The widget now provides a short verification guide and a direct next-step link. Verified users and guests retain the normal assistant; private chat/commerce verification requirements are unchanged. |

## Workflows exercised

- Customer: sign-in boundaries, registration/verification, account editing and recovery from invalid input, saved addresses and pins, catalog selection, cart persistence after logout, checkout progress, payment attempts/confirmation, purchase lists, delivery receipt/review and refund protections.
- Staff: phone dashboard, orders, operations, refund request viewing, reviews, walk-in entry, cash/online controls, payment/delivery recovery and allowed assistance.
- Owner: tablet dashboard, catalog/archive/variant controls, staff accounts, FAQ/settings forms, inventory search, reports, forecasts, refund inspection/approval and activity records.
- Transaction failures: malformed checkout replies, callback before checkout-save completion, retries, competing cash/online claims, duplicate sources, unchanged source receipts, and preserved stock on rejected confirmations.
- Pricing: the browser showed 6 pieces at PHP 852.00 wholesale, and 101 pieces at PHP 13,837.00 re-seller base less PHP 691.85 bulk discount = PHP 13,145.15. No sale or real payment was submitted for these browser checks.

Browser checks covered 8 customer and 8 staff screens at 390px phone width and 12 owner screens at 820px tablet width. All reached their intended screens, showed no captured application JavaScript errors, and stayed within the page width; large tables/graphs keep their own scrolling areas. The customer cart restored its three saved pieces after explicit sign-out and sign-in.

## Evidence and limits

A full feature suite passed 1,805 tests and 15,973 assertions after the cart/activity regression fix. Three additional malformed-filter cases then reproduced server errors and passed after correction. **The final complete release suite passed 1,808 tests and 15,982 assertions, with no failures, errors or skipped tests.** The result is retained in the private acceptance report and JUnit log.

The tests use disposable in-memory SQLite and controlled provider responses. They test transaction outcomes and deliberate request interleavings; they do not prove real MySQL simultaneous-process locking behavior. The hosted publication checks the deployed file hashes and preservation of real MySQL records, but does not replay real customer purchases.

Fresh real-wallet payments, real refunds, actual courier dispatch, personal password entry and inbox delivery were not repeated in this pass. Existing service settings and previously verified connections are retained. These provider actions require their own authorized test completion and should be distinguished from automated simulation.

The approved images remain photorealistic demonstrations. The previously deferred five study-alignment items and the planned customer survey remain separate work. Human ease-of-use ratings should come from measured customer/staff/owner evaluation, rather than being inferred from a test count.

Provider reference used for supported checkout identifiers: [PayMongo Checkout Session Resource](https://docs.paymongo.com/reference/checkout-session-resource). The creation check uses the same compatibility envelope already required by the application's retrieval/recovery flow.
