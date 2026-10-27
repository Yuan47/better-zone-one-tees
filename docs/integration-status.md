# Current integration checkpoint

Updated 27 October 2026 (Asia/Manila). This checkpoint separates actual provider results from simulated tests. Step 3 remains in progress; production launch is not established by these results.

| Service | Verified result | Remaining acceptance |
|---|---|---|
| Brevo | Registration verification and password recovery previously completed with the controlled test inbox. Three-day and one-day reminder messages both arrived; repeating the reminder command sent no duplicates. Local verification, recovery, reminders and owner-managed staff setup are enabled. | Actual staff setup OTP, a fresh main-site recovery-link pass, sender-domain authentication and scheduled execution. |
| Google | Actual new customer sign-in completed, using Google's verified email. The created role was Customer; an actual customer request to the admin page was rejected. Normal password registration still requires its separate email verification. | Additional actual cancellation/linking/mobile cases and the eventual public-domain callback. |
| PayMongo | Isolated TEST full payment, provider-confirmed unpaid expiry, failed test authentication, PHP300 deposit and PHP780 final balance passed. The same PHP1,080 invoice and original deadline were retained; stock was committed once. A second partial invoice was rejected while a full-plan reservation remained allowed. | Signed-in full browser journey, provider webhook redelivery, actual inspected refund verification, and main-store activation. |
| PayMongo receipt email | Customer confirmed the PHP300 deposit receipt arrived. Both provider checkouts requested receipts using the correct inbox. | PHP780 balance receipt remains missing; correct request data does not prove inbox delivery. Do not repeat a payment to obtain an email. |
| Lalamove | PH sandbox authentication, Cavite motorcycle coverage and PHP44 quotation passed. After an explicit insufficient-credit rejection, virtual Sandbox credit enabled one separate booking. Duplicate blocking, real refresh and same-ID recovery passed. Portal test-driver actions were verified through the actual API as `ON_GOING`, `PICKED_UP` and `COMPLETED`. Courier completion was recorded while local fulfillment stayed With courier, with customer confirmation/review still pending. | Terminal cancellation/rebooking, actual customer receipt/review and scheduled synchronization. The portal completion appeared before an immediate API read; a later bounded GET confirmed completion while preserving both observations. No production courier booking. |
| GroqCloud | The saved key returned HTTP200 for synthetic customer, staff and owner contexts. Before sending, the guard verified customer/staff/owner audience separation and absence of unrelated private settings. The local widget connected. | Continue real-browser answer-quality checks after the catalog-guidance correction; generative answers are not an authority for store policies or financial facts. |
| ngrok | Restored the existing restricted payment callback tunnel. Public admin and registration pages remain blocked; unsigned callbacks are rejected. | Temporary storefront domain/HTTPS, deployed return links and operational hosting acceptance. GroqCloud provides chatbot inference; ngrok provides public callback transport. |

## Corrections during acceptance

- Invalid, malformed or expired delivery quotations are cleared before payment creation or stock reservation. Changing an address requires a fresh quotation. Signed Lalamove requests do not follow redirects.
- Live browser testing caught an unsupported AI fabric claim. Recommendations, material questions and product descriptions now use verified catalog names and offered size/color combinations with available stock. Unknown fabric and comfort details require staff confirmation. FAQ and commerce rules retain priority. The changed chatbot/FAQ suites passed 149 tests / 1,260 assertions, followed by successful actual Groq requests for all three roles.
- The default customer registration guide now mentions the required full name and unique username and distinguishes Google customer sign-in. The additive migration changed one untouched default guide and preserved other settings. Owner-edited answers are preserved.
- A confirmed insufficient-credit courier rejection returns to Ready with owner guidance. Unknown outcomes retain their claim and block duplicate bookings. The focused delivery/checkout suites passed 216 tests / 1,776 assertions.

## Next execution order

1. Finish terminal cancellation/rebooking and combined customer/staff/owner browser acceptance. Preserve the original failed attempt, successful attempt and one-shot claims.
2. Resolve parcel weight/size and vehicle-capacity selection before enabling bulk production deliveries; the actual acceptance parcel contains one synthetic shirt.
3. Verify the missing PayMongo balance receipt and remaining webhook/refund cases; complete actual staff OTP and customer-return workflows.
4. Only then complete Step 4: temporary domain, HTTPS, public links, scheduled tasks, backup restoration and mobile/tablet checks.
5. Complete Step 5 with observed customer/staff/owner usability results and measured comparison against the old website. No 9/10 rating or forecast-accuracy percentage has been established.

Synthetic test fixtures are separate from the store database. No production payment or courier booking was used. Private keys, contacts, checkout URLs and raw provider responses stay outside GitHub.
