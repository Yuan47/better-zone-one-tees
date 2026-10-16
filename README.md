# Better Zone One Tee’s · 2.0

A web-based Laravel 13 / PHP 8.4 / MySQL clothing store, developed locally in XAMPP and intended to replace the earlier website after acceptance testing and deployment.

## Local use

Project directory: C:\xampp\htdocs\better-zone-one-tees

Start Apache and MySQL in XAMPP. This machine has a separate Apache preview configured at http://localhost:8081 because its default virtual host belongs to the older project. Open that address.
On a standard XAMPP installation without the older virtual host, use http://localhost/better-zone-one-tees/public.
Production document root must point to public. The root .htaccess denies source access under XAMPP and redirects the root URL into public. Requires mod_rewrite. Never remove that protection.

Alternatively run:

    php artisan serve --host=127.0.0.1 --port=8000

Set APP_URL to the active public URL when testing provider redirect URLs.

Fresh checkout:

    composer install
    copy .env.example .env
    php artisan key:generate

Create a MySQL database named better_zone_one_tees, configure DB_* in .env, then:

    php artisan migrate --seed
    php artisan test

No frontend build step or external CDN is required. Uses Blade, CSS, JavaScript, original SVG clothing illustrations, and locally bundled Leaflet assets. The delivery map loads OpenStreetMap tiles over the internet; coordinates can also be entered manually.

| Role | Email | Local demo password |
| --- | --- | --- |
| Admin | admin@zoneone.test | ZoneOneDemo!2026 |
| Staff | staff@zoneone.test | ZoneOneDemo!2026 |
| Customer | customer@zoneone.test | ZoneOneDemo!2026 |

Demo user seeding is limited to local/testing. The first catalog batch contains the six supplied Dannon, Lucky Hanna, and Thanos products, with WHITE and COLORED options in one product. It includes 13 named colors and 628 size/color variants at the supplied Retail, Wholesale, and Re-seller prices. Inventory starts at zero until the owner enters physical counts. Existing passwords and inventory are preserved on repeated imports.

To upgrade an existing installation without reseeding accounts:

    php artisan migrate --force
    php artisan shop:import-catalog
    php artisan view:clear

The import archives the eight original sample styles once and preserves their variants and order history. Repeated imports leave owner changes intact. Hidden products can still be reviewed in the admin catalog.

## Quantity pricing

Total pieces across the entire cart, regardless of role:

- 1–5: owner-set Retail/SRP.
- 6–50: owner-set Wholesale.
- 51–100: owner-set Re-seller.
- 101+: Re-seller clothing subtotal less 5%.

Bulk discount rounds once to the nearest centavo on the whole clothing subtotal. Shipping is separate and never discounted. Money is stored in integer centavos. Browser-supplied prices are ignored. Bulk PayMongo checkout groups clothing into one line to preserve the exact order-level discount.

## Email account verification

Account verification is prepared through Brevo's transactional HTTP API. Set EMAIL_VERIFICATION_REQUIRED=true only after configuring BREVO_API_KEY, BREVO_FROM_EMAIL, and BREVO_FROM_NAME with a verified sender. The local example keeps verification off; production defaults to requiring it if the flag is absent. Payment reminders have their own independent switch.

New registrations receive a six-digit code when enabled and configured. Existing unverified customers, staff, and admins sign in and request their own code. Codes expire after ten minutes, allow five incorrect attempts, and have a sixty-second resend wait and five-send hourly account limit. Only a keyed digest is stored in the database. Resending replaces the old code; successful verification is single use and records an activity entry. GET requests never send mail or verify accounts. Failed and uncertain sends are not retried automatically.

When required, unverified accounts can browse public products and view their account/verification screen, but protected orders, checkout, account changes, and staff/owner workspaces are blocked on the server. Provider webhooks and signed guest walk-in invoices remain independent so existing payment reconciliation is not interrupted. Existing accounts are not automatically marked verified, and passwords, invoices, balances, and deadlines are preserved. This is inbox verification, not two-factor login or password recovery.

The responsive verification screen includes one-time-code autofill, a resend countdown, safe errors, sign-out, and help for the fixed account email. My Account shows verified/unverified status; owner settings show setup readiness without displaying credentials. Provider acceptance does not prove inbox delivery. Follow [Email verification setup](docs/email-verification-setup.md) for temporary-domain HTTPS, sender authentication, safe activation, and real-inbox checks.

## Invoices, installments, and walk-in sales

Customers may choose partial payment only when the discounted clothing subtotal is **more than ₱1,000.00**, excluding delivery. Exactly ₱1,000.00 does not qualify. Each payment must be at least ₱1.00 and cannot exceed the outstanding invoice balance; an initial partial payment must be less than the full invoice total. Deposits remain separate from confirmed clothing sales and do not make an order eligible for packing or delivery.

Only one open installment invoice is allowed per identified customer. Both pending installment invoices with no money received yet and partially paid invoices block another installment purchase. Customer account IDs and normalized Philippine mobile numbers are checked under database locks before reserving stock. A new purchase paid in full remains available. Payments toward the existing installment remain on that invoice; they do not create a second order or refresh its credit allowance.

Guest installments require a valid Philippine mobile number. This is an identity matching limit, not verified identity or a credit assessment: there is no phone OTP, and an unrelated guest number can evade a phone-only limit. Staff must confirm the person and contact details through the store's agreed process. Registered customers retain their account-based limit even if their checkout number changes.

The balance deadline is 7–30 days from the original invoice creation. The default is seven days, configurable by the owner; checkout can select a permitted term and walk-in checkout can select a permitted date. Further payments preserve the invoice number, clothing/quantity/price snapshots, original total, and original deadline. Only an admin can record a later agreed deadline, with a reason, still within 7–30 days of the original creation. Changing the default applies to new invoices, not existing ones. Overdue invoices and current outstanding balances are visible to the store; late payment does not create a new invoice or add automatic interest.

Staff/admin can create a store-pickup walk-in invoice for a registered customer or a guest without creating a fake account. A linked customer's invoice appears in that customer's order history. Full/partial cash and full/partial PayMongo are supported. Cash requires an explicit payment-received confirmation and a written receipt note; the receipt, amount received, stock transition, and financial activity entry commit together. PayMongo amounts are received only after verified provider confirmation. Request UUIDs and invoice locks prevent repeated submissions from crediting the same payment twice or accepting more than the balance.

Fully received cash or verified full PayMongo settlement marks a walk-in order Delivered and records its walk-in receipt time, without an online customer review. Partial walk-in invoices stay open. This implements the store's walk-in handover rule; payment confirmation is not independent evidence of physical handover, so staff must follow the store's collection process. Online orders retain their separate delivery and customer-review requirements.

Unregistered walk-in customers receive a private, expiring signed invoice link to view items/receipts/balance, start or check PayMongo payments, and request an eligible refund. The link is a bearer capability: anyone possessing it can view that invoice and use its allowed actions. It must be shared privately. Signature validation, expiry, CSRF, and request limits protect the endpoints; this does not verify the guest's identity. No guest account or Google sign-in is created.

## Available functionality

Catalog search/size/color filters/sort, cart edits, automatic pricing, registration/login, wishlist, protected order history, checkout review. Storefront purchases and delivery quotations require a customer account; staff and owner accounts use the separate walk-in checkout for store transactions. My Account is available to customers, staff, and admins: editable username/name/photo, fixed email, and password changes with current password and confirmation. Customers can save a structured delivery address that prefills checkout.

Admin product create/edit/hide includes product and variant image uploads, automatic SKUs, owner-defined colors, size charts, stock, and prices. New color batches copy the existing colored size prices and start with zero inventory. Size charts use Width and Length in inches. Dannon and Lucky Hanna polo measurements follow the supplied charts; other adult tees use the Yalex sample chart, including the confirmed 4XL 24 × 31. For kids, only sizes 10 and 12 currently match the supplied chart; other priced sizes remain “To be confirmed” and editable. Actual product photos are still to be supplied.

Admin/staff dashboards show Confirmed Clothing sale, Average Order Value, best sellers, inventory, recent orders, and Sales over time with Daily, Weekly, Monthly, and Yearly views. The sales chart has an inclusive date range, an accessible line graph, and exact figures in a table. Best sellers are grouped by stable product IDs, so renaming a product keeps its historical units together; order details retain their original snapshots. The inventory preview puts the most urgent shortages first and links to the full paginated list, searchable by product, SKU, size, and color, with product and out-of-stock filters. Sales & Growth Forecast and Operations overview have separate pages. Customer management, packing, CSV export, and chatbot FAQ settings remain available. Staff can manage fulfillment/reports and inspect refund requests; catalog/customer/settings edits, refund decisions, and attaching previously unknown checkout references require admin. Categories and reserved quantities are absent from the interface; internal payment stock reservations still prevent overselling.

Uploads accept JPEG, PNG, and WebP only: product/variant photos up to 5 MB and account photos up to 2 MB. Files use generated names under public/uploads; executable uploads are blocked. Keep uploaded files backed up and preserve them across deployment. This project's Apache settings allow a 6 MB file/8 MB request; configure equivalent limits on other hosts. Images and personal data are excluded from Git.

PayMongo hosted Checkout v2 adapter, signed webhook verification, amount/currency/session/reference/mode checks, duplicate protection, locked inventory reservations, and payment recovery when a webhook is missed. Returning from checkout never confirms payment. Unconfigured checkout is disabled. Valid provider payment timestamps are used for sales reporting when supplied; a missing timestamp uses the confirmation time, and an invalid supplied timestamp refuses recovery.

Lalamove sandbox HMAC adapter, delivery pin selection, exact-address quotations, re-quotation/booking after packing, tracking links, scheduled delivery synchronization, and staff booking recovery/rebooking. Store pickup works without delivery credentials.

Grok widgets use server credentials and scoped customer/staff context. Without a key, labeled basic FAQ/catalog/order answers work. Customers only access their own orders. AI cannot change orders/payments. Sizing, policies, and recommendations depend on approved store data.

Store settings now separates the FAQ library from store information. Admins can add, edit, activate/deactivate, and delete individual questions and answers, or upload a CSV using the downloadable template. Search/status filters and 12-entry pages keep the library manageable. Only active FAQs are supplied to the chatbot, including its basic mode when Grok is not connected; Grok receives at most ten relevant FAQ entries per question. Pricing, payment confirmation, and order status answers come directly from store facts even when Grok is connected. CSV imports validate all rows before saving, with a limit of 100 rows and 1 MB per file. Opening hours, pickup information, support contacts, and additional approved store notes have their own fields. Previous free-text guidance is preserved in the store note during the upgrade.

The admin-only Activity log records meaningful actions from the upgrade onward. It covers sign-in/out, registration, profile/password changes, cart/wishlist changes, order creation, staff fulfillment/payment/delivery recovery actions, cash receipts, agreed deadline/default-term changes, refund requests/reviews/verification/cash returns, catalog/FAQ/store information changes, and report exports. Filter by person, role, event, and date; results are paginated and displayed in Asia/Manila time. Failed validation and unchanged edits do not create entries. Logs contain actor snapshots and limited action details, never passwords, tokens, raw request bodies, customer addresses, or chat messages. Guest invoice refund requests are labeled as guest invoice holder actions without inventing an account. Passive browsing is not recorded, and the admin interface has no log deletion action.

The web interface adapts to mobile and Android phone browsers: visible storefront search, an accessible admin menu with account/sign-out access, larger touch controls, readable form fields, contained table scrolling, and a chat panel sized for small screens. No Android app installation is required.

The delivery monitor starts below the Operations date filters, ahead of the main financial cards. It uses spacious summary cards, a status ring with an exact-count legend, and a horizontal status chart with staff guidance. It counts only fully paid, non-cancelled Lalamove orders placed in the selected dates; pickups are separate. Every status opens matching orders while preserving the dates, payment confirmation and delivery-method filters. The page shows the current state of those orders, not historical status-transition events. On phones the charts stack vertically and a Delivery charts button jumps directly to them.

Paid orders use four delivery stages throughout the charts, order pages, filters, and chatbot: **Packing → Ready for delivery → With courier → Delivered**. Booking problems appear as guidance within Ready for delivery. Unpaid/canceled payment records and refund records remain separate from this fulfillment chart. Staff can mark packing complete only with a written reason; the status change and its activity entry commit together or both roll back. Request fields cannot change invoice amounts, payment confirmation, ownership, stock commitment, or customer receipt.

For online orders, Lalamove's verified COMPLETED response records courier arrival but keeps the order With courier. Only the owning customer can confirm Delivered, with a required 1–5 rating, a review of at least ten characters, and a receipt checkbox. A negative review is accepted. Online ready pickup orders use the same customer confirmation. Repeating the request preserves the first review, confirmation time, and single activity entry. Staff/admin cannot perform this online customer confirmation. Legacy completed online orders without a customer receipt are reopened for confirmation; the upgrade never invents reviews, receipt times, or courier-arrival evidence. Old Lalamove orders need a verified provider completion before customer confirmation becomes available. Fully settled walk-in orders follow the separate store handover rule above.

## Forecast formulas

Historical growth:

    growth rate = (last completed month sales - preceding month sales) / preceding month sales
    forecast = last completed month sales × (1 + growth rate)

Linear regression:

    y = a + bx
    b = sum((x - mean(x)) × (y - mean(y))) / sum((x - mean(x))²)
    a = mean(y) - b × mean(x)

Uses up to six completed months and excludes the current incomplete month from its inputs. x is 1..n; x=n+1 forecasts the current month. Covers confirmed clothing sales after the quantity discount and unit demand per active product. Each product has its own history, beginning in its catalog creation month or an earlier genuine paid-sale month for imported history. Months before introduction are excluded; zero-sale months after introduction remain part of the history. A product without observed completed-month paid sales is labeled as needing history rather than receiving a zero-demand prediction. Growth requires two periods with a nonzero earlier period; regression requires three. Negative demand predictions display zero. Historical Units sums pieces sold in the displayed product periods. Suggested Reorder is max(0, rounded-up regression demand minus available stock); it is a planning estimate, without supplier lead times or safety stock. The page reveals each product's periods, history basis, rate, intercept, slope, and target month. Backtesting on actual sales is still needed.

Confirmed Clothing sale sums fully paid online and walk-in clothing subtotals after the bulk discount and excludes delivery fees. Deposits do not enter sales, AOV, best sellers, or forecast history. Average Order Value divides that same total by the number of fully paid orders. The sale is recognized on final settlement, not on the deposit date. Sales periods use paid_at in Asia/Manila; weekly periods begin Monday. Without selected dates, sales cards and best sellers show all-time values while the chart shows the last 7 days, 8 weeks, 12 months, or 5 years. With both dates selected, the sales total, paid-order count, AOV, best sellers, chart, and CSV export use the same inclusive range. Customer totals remain lifetime counts and packing counts reflect the current queue. Current incomplete chart periods are labeled. Daily/weekly ranges allow at most 366 days; monthly/yearly ranges at most 10 years, with a maximum of 120 chart periods for every grouping. No future dates or estimated sales are accepted.

The dashboard also compares Fully paid, Partially paid, and Unpaid invoice counts, and fully paid Store pickup/Lalamove counts. These count charts use the **date the order was placed**, with all-time counts when dates are blank, so unpaid invoices can be compared fairly. They exclude cancelled and released invoices; other legacy payment statuses or delivery methods are disclosed separately. Counts and percentages have exact filtered order links, including pagination and permitted long ranges. They share the date controls with sales but have a clearly labeled placement-date basis; they need not equal payment-date sales totals.

Operations overview tracks orders placed within its selected dates, their current fulfillment/payment status, pickup/Lalamove counts, and refund queues. It defaults to the last 30 days and supports Daily/Monthly trends with ranges up to 366 days. Its payment/method count charts use the same non-cancelled, non-released placed-order cohort. Outstanding balances and deposits are current amounts on invoices placed in those dates, not a cash-receipts-by-payment-date report. Cancelled paid invoices remain in financial paid totals for reconciliation but are excluded from fulfillment/count comparisons, with an explanation. Provider-confirmed refunds use confirmation dates, and audited cash refunds use their recording dates; both appear as separate events and gross amounts. A refunded order remains in its current fulfillment status. Refund amounts may include delivery and are not automatically subtracted from Confirmed Clothing sale because a clothing/delivery refund allocation has not been recorded.

## Payment, delivery, and refund recovery

Customers can check or close their own pending checkout; staff/admin can do this for store orders. Closing a checkout requests provider expiry and then retrieves its status again. A verified expired, unpaid installment checkout closes that payment attempt; an invoice with a deposit remains open with its stock held and balance unchanged. A zero-funded invoice releases stock exactly once only when expiry is verified and there is no paid or unsettled payment. Processing payments, missing/conflicting details, and network failures keep the reservation. If a payment succeeded, recovery applies its receipt to the same invoice and commits inventory only when the invoice is fully settled. An owner can attach an unknown checkout reference after verifying the invoice/payment reference, amount, mode, and that it is not attached to another order.

UNPAID_CHECKOUT_MINUTES defaults to 30, with a minimum of 15. Every five minutes the scheduler checks pending/partially paid invoices with known checkout references or saved definitive creation rejections. It attempts verified expiry once the active payment attempt reaches the threshold, using invoice creation time when there is no active attempt. The threshold does not guarantee release at exactly 30 minutes: uncertain states and deposited invoices remain held.

A structured PayMongo checkout-creation rejection (HTTP 400/401/403/422, nonempty errors, and no created checkout ID) is terminal and permits a new online/cash request on the same invoice. An unfunded invoice in that state can be closed safely if no other active attempt, paid receipt, or unresolved legacy checkout exists. Timeouts, server errors, and malformed success responses remain unknown and require reconciliation; they cannot be retried blindly. The following command applies only to an unfunded, sessionless invoice without an active payment attempt, after the operator has confirmed no payment exists:

    php artisan shop:release-reservation ORDER-NUMBER --confirmed-no-payment

The confirmation flag does not override those guards. It cannot release a deposited invoice or bypass an unknown active attempt.

Uncertain delivery bookings remain blocked from another booking. Staff/admin can verify a known delivery or attach the provider ID of an uncertain attempt; pickup/recipient details, coordinates, order metadata, mode, and existing ownership must match the saved booking. Verified CANCELED, REJECTED, or EXPIRED attempts are retained in delivery history, return the order to ready for handover, and allow a fresh quote/booking. Unknown statuses or failed checks preserve the existing booking. Confirmed courier arrival cannot be reversed by a later contradictory status. Existing attached pending/processing refunds and full confirmed refunds continue to hold fulfillment; a legacy local approval before delivery does not strand shipping.

Refund requests require full payment and confirmed delivery: the owning customer's receipt/review for online orders, or completed store handover for walk-in orders. A private guest invoice holder can request store review without creating an account. These gates also apply to owner decisions and new provider-reference attachment. Staff/admin can inspect the queue and check already attached references; only the owner/admin can approve/reject requests and process the store's refund record. Approval specifies an amount, explanation, and paid receipt from the same invoice. Receipt-level and invoice-level caps prevent refunding the same money twice, including invoices paid through multiple cash/PayMongo installments.

For PayMongo, the owner processes the actual refund externally and enters its reference here. The application verifies it through a provider GET request against the selected receipt's payment, currency, mode, amount, ownership, status, and confirmation timestamp. Existing attached legacy refunds may still be reconciled. For cash, only the owner can record an approved cash return after physically returning the money; a paid cash receipt, remaining balance cap, written reason, and matching financial audit are required. It is labeled Recorded cash refund, never PayMongo confirmed. Approval alone does not confirm money returned. There is no financial provider refund POST or automatic restocking; stock changes require physical return inspection and an owner inventory update. Refund requests are distinct from a complete returns/disputes workflow.

## Payment reminder email

Brevo reminders are implemented but **disabled by default**. After configuring a verified sender, enable BREVO_PAYMENT_REMINDERS_ENABLED explicitly. The hourly scheduler checks outstanding, non-cancelled/non-released invoices due in three days or one day, using Asia/Manila calendar dates. Registered invoices use the customer's account email; guest walk-in invoices use their optional contact email. Missing/invalid recipients are skipped. Registered links require login; guest links are private, expiring signed capabilities.

One reminder claim is stored per invoice, due-date snapshot, and lead time before making the HTTP request. Repeated runs do not resend claimed, accepted, failed, or uncertain attempts. Failures, timeouts, interrupted claims, and provider refusals need operator review; there is no automatic retry or overdue-email campaign. A later agreed deadline has its own snapshot. Brevo acceptance with a message ID is recorded, but acceptance does **not** establish inbox delivery; delivery/bounce event tracking is not implemented.

Preview eligibility without sending mail or saving claims:

    php artisan invoices:send-reminders --dry-run

Run the enabled sender through the scheduler or manually:

    php artisan invoices:send-reminders

## Scheduler

For local development:

    php artisan schedule:work

In production, invoke Laravel's scheduler every minute from the hosting scheduler/cron, using the installed PHP binary and the absolute project path:

    * * * * * cd /absolute/path/to/better-zone-one-tees && php artisan schedule:run >> /dev/null 2>&1

Payment and delivery synchronization run every five minutes; invoice reminders run hourly in Asia/Manila and send nothing unless enabled and configured. All three use overlap protection. Configure a shared cache for scheduler locks when running more than one application server and ensure one intended scheduler execution per minute. Without the scheduler, staff checks remain available but background recovery/tracking and reminders do not run. Queue workers will be needed if asynchronous jobs are added. Configure database/upload backups separately; these scheduled commands are not backups.

## Connect providers

Credentials belong in .env only; it is ignored by Git. Never put keys in chatbot FAQs.

PayMongo: PAYMONGO_MODE=test, test secret key, webhook secret. Register checkout_session.payment.paid at a reachable HTTPS URL ending /webhooks/paymongo. Localhost cannot receive provider webhooks; use a trusted tunnel or staging deployment. APP_URL must match. Only webhooks bypass browser CSRF. Verifier supports legacy/current send.webhook envelopes, configured test/live signature, and five-minute timestamp tolerance.

Lalamove: sandbox key/secret, actual pickup address/coordinates/phone, supported service type. Customers can tap the checkout map, drag its pin, or choose “Use my current location”; location permission is requested only when that button is pressed. Manual latitude/longitude entry remains available. Production browser geolocation requires HTTPS and user permission; localhost can be used for local testing. The pin and structured address must be checked together; automatic address geocoding is not implemented. Map tiles require internet access. Quotes expire in minutes, so booking requests a fresh quote. If its fee exceeds collected delivery fees, staff resolution is required. API wallet expenses and customer payments are separate.

Grok: GROK_API_KEY and GROK_MODEL available in your xAI account. No external request occurs until configured. Account model availability can vary.

Brevo: BREVO_API_KEY, BREVO_FROM_EMAIL, and BREVO_FROM_NAME, using a sender verified with the provider. BREVO_PAYMENT_REMINDERS_ENABLED=false is the default. Confirm addresses, sender setup, quota, scheduler behavior, and real inbox/bounce results before enabling store reminders. The implementation sends through the Brevo HTTP API; configuring Laravel SMTP alone does not enable these reminders.

External providers have not been end-to-end verified with your credentials. Automated tests exercise mocked provider responses, signed webhook payloads, recovery failures/races, receipt caps, reminder handling, role boundaries, date aggregation, and forecast history. Passing these tests does not establish real account eligibility, payment settlement/refund timing, Lalamove service coverage/wallet behavior, Brevo delivery, or Grok model access. Complete sandbox and controlled live verification before cutover. Browser responsive checks do not replace testing on physical Android devices, including GPS permission/failure, slow map loading, hosted checkout, and the on-screen keyboard.

Google sign-in is not implemented in this Laravel project. Configuring a Google client alone will not add authentication routes, account linking, or callback protections. ngrok/public webhook reachability and provider credentials remain unconfigured and untested here.

## Email verification checks · 16 October 2026

The complete SQLite suite passed **749 tests / 6,037 assertions**. The 114 new OTP service and account-flow cases also passed on isolated MariaDB with **765 assertions**. Independent two-process checks passed **27 assertions**: competing verification requests produced one success and one activity entry, and competing sends produced one claim and one mocked provider request. The disposable schema, accounts, and grants were removed after verification. Provider requests were mocked; no real email was sent. Evidence is retained locally under work/email-otp-final-junit.xml and work/email-verification-mysql-20261016.

## Installment revision verification · 16 October 2026

The final full SQLite suite passed **635 tests / 5,272 assertions**. Fifteen focused suites also passed **502 tests / 3,730 assertions** on MariaDB 10.4.32. A later MariaDB follow-up passed **67 tests / 696 assertions**, including the final checkout replay and reminder-visibility changes; these overlap the earlier run and are not additional unique tests. All runs had zero failures, errors, or skips. MariaDB verification used a guarded disposable schema and an account restricted to that exact schema, with InnoDB, strict SQL mode, and REPEATABLE READ. Coverage includes invoice payments, walk-ins, customer installments, credit limits, reminders, mixed-receipt refunds, existing commerce/payment/delivery recovery, reports, Operations, and real CSRF middleware. Provider HTTP was mocked; no payment charge or real email was sent.

Two independent-process probes used the actual services and passed **24 saved invariant checks**. Competing full ₱1,800 cash submissions produced one receipt, one financial audit, one stock deduction, and no invoice-price changes; the second waited 2.120 seconds then failed safely as already paid. Competing partial OrderFlow reservations for one customer produced one installment invoice and one stock hold; the second waited 2.073 seconds then failed the credit rule before creating another order/payment attempt. These probes verify exercised local races, not general load capacity.

Browser acceptance on the isolated simulation verified a walk-in invoice paid in two cash receipts, a customer installment with its unchanged balance and home alert, a separate full-payment purchase while that installment remains open, and rejection of a second installment. The Operations delivery ring and status bars sit above the financial totals; separate graphs explain paid/outstanding invoices and paid pickup/Lalamove orders. Invoice and Operations pages had no horizontal page overflow at a 390-pixel viewport. All compiled views and changed JavaScript passed syntax checks, and the changed PHP files passed the formatter check.

The local XAMPP website was upgraded after a maintenance window and database backup. Both new migrations completed, checks preserved the original columns and data in 14 existing business tables, and the website returned HTTP 200. No paid invoices were present in that local database to backfill; legacy receipt backfill is covered by automated fixtures. Brevo remains disabled and unconfigured; its dry run found no eligible reminders and sent nothing.

Evidence is retained in the workspace: work/installments-final-junit.xml, work/installments-final-suite.txt, work/payment-verification-20261015, and work/installment-deploy-20261016. The disposable MariaDB schema and test accounts/grants were removed, their temporary credential was cleared, and a connection attempt with the retired credential was rejected. The accompanying local zone-one-installments-test-report.md and screenshots record scope and limitations. GitHub publication is recorded separately after the source commit is verified.

## Remaining before replacing the live store

- For each further installation, apply migrations only after a verified backup and review of existing financial data; the ledger migration preserves receipts and refuses destructive rollback. The completed local upgrade does not migrate the old hosted store.
- Configure provider credentials, public webhook HTTPS reachability, pickup details, and scheduler execution; verify payment success/expiry/recovery and delivery booking/recovery with the real sandbox accounts. Keep Brevo reminders off until sender/inbox behavior is verified.
- Confirm product photos, remaining kids' measurements, stock counts, opening hours, support details, and the store's refund/physical-return procedures. PayMongo refunds are processed externally and cash returns are performed manually by the owner; disputes, return inspection, and clothing/delivery refund allocation still need a defined workflow.
- Password recovery, notifications beyond the implemented verification codes and three-day/one-day balance reminders, support escalation tickets, backup automation, and load testing remain. Activate and verify real Brevo inbox delivery before requiring account verification on the deployed domain. Guest phone identity is unverified; store credit/collection procedures still need acceptance.
- Test the deployed site on physical mobile devices and verify backups can be restored. Forecast accuracy needs historical-data backtesting.
- Replacing the earlier website still requires a reviewed catalog/customer/order migration, controlled provider live testing, acceptance, rollback, domain configuration, and HTTPS. This source update does not perform hosting or database cutover.

## GitHub / deployment

GitHub hosts source; the web store needs a PHP/MySQL host. Commit source/composer.lock; never .env, vendor, customer data, database dumps. Included GitHub Actions runs tests and compiles templates.

Production requires APP_ENV=production, APP_DEBUG=false, SHOP_DEMO=false, a fresh secure APP_KEY, restricted database credentials, HTTPS/session cookie security, public document root, and verified provider live accounts. Remove/rotate demo users and replace sample inventory. PayMongo live keys require demo mode off plus HTTPS APP_URL. Lalamove production is blocked in demo mode. Configure trusted proxies and shared infrastructure for the host.

Deploy via Git, install dependencies, run reviewed migrations with php artisan migrate --force, and cache configuration/views. Public hosting/domain cutover has not been performed.

References: [PayMongo checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [webhook signatures](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [Lalamove](https://developers.lalamove.com/), [xAI API](https://docs.x.ai/developers/rest-api-reference/inference).

## Brand identity

Uses the supplied Zone One Tee’s logo and palette: Coffee Bean #220901, Dark Garnet #621708, Oxblood #941B0C, Rusty Spice #BC3908, Orange #F6AA1C, and White #FFFFFF. White backgrounds keep the shopping/admin layouts readable; garnet/oxblood serve controls, orange highlights, and rusty spice charts.
