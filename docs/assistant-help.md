# Customer and workspace assistance

The assistant includes 28 editable how-to guides: 12 for customers, 9 for staff and owners, and 7 for owners. They explain account creation, verification, recovery, shopping, invoices, installments, receipt/review, returns, counter sales, fulfillment, reporting, forecasts and owner tools in short numbered steps. Matched approved guides work without Grok credentials.

The chat panel offers three quick questions appropriate to the page and signed-in workspace role. Answers preserve line breaks so steps remain readable on mobile. The assistant explains actions; it cannot perform payments, refunds, stock changes, staff activation or other management actions.

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

Automated tests check role selection, context filtering, hidden/inactive guides, forged audience requests, private-order boundaries, FAQ routing, imports, quick-question visibility and offline help. AI tests use simulated responses; these checks do not establish the real provider's availability or answer quality. Real Grok acceptance and participant usability measurements remain separate integration and defense tasks.
