# Customer and workspace assistance

The assistant includes editable how-to guides for customers, staff and owners. They explain account creation, verification, recovery, shopping, full-payment invoices, receipt/review, returns, counter sales, fulfillment, reporting, forecasts and owner tools in short numbered steps. The missing-receipt guide directs customers to the original invoice and warns against paying again to obtain an email. Matched approved guides work without GroqCloud credentials.

The chat panel offers three quick questions appropriate to the page and signed-in workspace role. Answers preserve line breaks so steps remain readable on mobile. The assistant explains actions; it cannot perform payments, refunds, stock changes, staff activation or other management actions.

Product recommendations, named-product descriptions and fabric/comfort questions use current catalog facts directly. Answers show only offered size/color combinations and available stock after reservations. Fabric, softness and suitability claims that are not established by the catalog require staff confirmation. Approved FAQs and verified price, stock, payment and order rules retain priority. General assistance can still use GroqCloud; no prompt rule guarantees that every generative answer is correct.

The default account-creation guide includes full name, unique username, email, matching passwords, email-code verification and customer-only Google sign-in. Its additive refresh changes only the exact old default answer and preserves owner edits.

New refund requests require a private supporting photo. The assistant's controlled refund-photo answer states the allowed image types, 3 MB limit, 6000-pixel side limit, and that evidence is not published as a product review. The additional product-review guide explains that existing ratings belong to completed orders containing an item, written comments remain optional, and buyer identities are masked. The migration refreshes only exact untouched refund defaults and preserves owner edits, audiences, inactive entries and sort order.

The simplified catalog guides explain separate adult/kids Dannon Round Neck and V-Neck products, White as an ordinary color, and copying a selected color's sizes/prices without copying stock. A staff/owner-only guide explains the read-only Customer reviews workspace and its order links. Catalog context supplies actual color names and no longer supplies a White/Colored grouping control. Existing owner-edited answers and audience decisions remain preserved.

The November major-revision guides explain full payment only, signup Terms and Conditions, required delivery pins with optional device GPS, saved/default addresses, carts that survive sign-out, the compact My Purchases view, generated demo photo limits, a simple dashboard and archive-before-delete product management. Obsolete installment-offering guides remain in the library as inactive history. New payment-policy questions use the full-payment rule even if someone reactivates an older installment guide. Existing recorded payments and outstanding invoice balances remain truthful; an older balance must be settled in full on the same invoice.

The [Lalamove demonstration note](lalamove-demonstration.md) is also a Staff FAQ and a collapsed note on Sandbox order pages. It separates virtual test-driver steps from a real courier delivery and explains reconciliation before retrying a booking. To Ship is the displayed name for an active courier booking; the stored status keys and order history are preserved.

## FAQ audiences

Only the owner manages the FAQ library in Settings. Choose an audience when adding or editing an answer:

| Audience | Who may receive the answer |
|---|---|
| Customer | Visitors and customers in storefront chat |
| Staff | Authenticated staff and owners in workspace chat |
| Owner | Authenticated owners in workspace chat |

Workspace chat also has access to customer FAQs. Choosing customer mode keeps the answer context public even when an owner is signed in. The server determines access from the authenticated account; a client-supplied role or audience cannot expand it. Unauthenticated users and customer accounts cannot select staff mode.

Inactive FAQs are excluded from both direct answers and AI context. Customer AI requests contain only public catalog/store information and permitted customer FAQs; internal FAQ answers and forecast data are excluded. Explicit internal-data requests receive customer-safe guidance without an AI request. Requested order records remain limited to the customer's own account. Staff order queries use existing workspace permissions. Forecast context is considered only for authenticated workspace questions about forecasting.

FAQ additions preserve existing answers, keywords, order and audience. Exact untouched default answers can receive targeted corrections, and the major-revision migration deliberately deactivates obsolete installment-offering guides without deleting their wording. Older custom entries started as Customer because the previous library was public. Review their audience before adding internal instructions. The known legacy staff-account setup guide was moved to Owner without changing its text or active flag.

CSV import accepts an optional `audience` column with `customer`, `staff` or `owner`. Missing or blank CSV audiences default to public Customer. Invalid audiences reject the whole import. Older edit forms without the audience field retain the saved scope, so they cannot accidentally publish an internal answer. Audience changes are recorded in the activity log.

Do not store passwords, private verification codes, recovery links, API keys or other secrets in any FAQ, including internal ones. Personal verification codes belong only on the relevant verification screen. Staff setup codes belong only on the dedicated owner staff-setup screen.

## Verification limits

Automated tests check role selection, context filtering, hidden/inactive guides, forged audience requests, private-order boundaries, FAQ routing, imports, quick-question visibility, grounded product guidance and offline help. Those tests use simulated provider responses. Separate actual Groq requests and browser checks are recorded in [Current integration checkpoint](integration-status.md); they do not establish future availability or universal answer accuracy. Participant usability measurements remain a separate defense task.
