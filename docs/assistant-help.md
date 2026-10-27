# Customer and workspace assistance

The assistant includes 28 editable how-to guides: 12 for customers, 9 for staff and owners, and 7 for owners. They explain account creation, verification, recovery, shopping, invoices, installments, receipt/review, returns, counter sales, fulfillment, reporting, forecasts and owner tools in short numbered steps. Matched approved guides work without GroqCloud credentials.

The chat panel offers three quick questions appropriate to the page and signed-in workspace role. Answers preserve line breaks so steps remain readable on mobile. The assistant explains actions; it cannot perform payments, refunds, stock changes, staff activation or other management actions.

Product recommendations, named-product descriptions and fabric/comfort questions use current catalog facts directly. Answers show only offered size/color combinations and available stock after reservations. Fabric, softness and suitability claims that are not established by the catalog require staff confirmation. Approved FAQs and verified price, stock, payment and order rules retain priority. General assistance can still use GroqCloud; no prompt rule guarantees that every generative answer is correct.

The default account-creation guide includes full name, unique username, email, matching passwords, email-code verification and customer-only Google sign-in. Its additive refresh changes only the exact old default answer and preserves owner edits.

## FAQ audiences

Only the owner manages the FAQ library in Settings. Choose an audience when adding or editing an answer:

| Audience | Who may receive the answer |
|---|---|
| Customer | Visitors and customers in storefront chat |
| Staff | Authenticated staff and owners in workspace chat |
| Owner | Authenticated owners in workspace chat |

Workspace chat also has access to customer FAQs. Choosing customer mode keeps the answer context public even when an owner is signed in. The server determines access from the authenticated account; a client-supplied role or audience cannot expand it. Unauthenticated users and customer accounts cannot select staff mode.

Inactive FAQs are excluded from both direct answers and AI context. Customer AI requests contain only public catalog/store information and permitted customer FAQs; internal FAQ answers and forecast data are excluded. Explicit internal-data requests receive customer-safe guidance without an AI request. Requested order records remain limited to the customer's own account. Staff order queries use existing workspace permissions. Forecast context is considered only for authenticated workspace questions about forecasting.

The new migration preserves existing FAQ answers, keywords, active flags and order. Older custom entries start as Customer because the previous library was public. Review their audience before adding internal instructions. The known legacy staff-account setup guide is moved to Owner without changing its text or active flag.

CSV import accepts an optional `audience` column with `customer`, `staff` or `owner`. Missing or blank CSV audiences default to public Customer. Invalid audiences reject the whole import. Older edit forms without the audience field retain the saved scope, so they cannot accidentally publish an internal answer. Audience changes are recorded in the activity log.

Do not store passwords, private verification codes, recovery links, API keys or other secrets in any FAQ, including internal ones. Personal verification codes belong only on the relevant verification screen. Staff setup codes belong only on the dedicated owner staff-setup screen.

## Verification limits

Automated tests check role selection, context filtering, hidden/inactive guides, forged audience requests, private-order boundaries, FAQ routing, imports, quick-question visibility, grounded product guidance and offline help. Those tests use simulated provider responses. Separate actual Groq requests and browser checks are recorded in [Current integration checkpoint](integration-status.md); they do not establish future availability or universal answer accuracy. Participant usability measurements remain a separate defense task.
