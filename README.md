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

## Available functionality

Catalog search/size/color filters/sort, cart edits, automatic pricing, registration/login, wishlist, protected order history, checkout review. My Account is available to customers, staff, and admins: editable username/name/photo, fixed email, and password changes with current password and confirmation. Customers can save a structured delivery address that prefills checkout.

Admin product create/edit/hide includes product and variant image uploads, automatic SKUs, owner-defined colors, size charts, stock, and prices. New color batches copy the existing colored size prices and start with zero inventory. Size charts use Width and Length in inches. Dannon and Lucky Hanna polo measurements follow the supplied charts; other adult tees use the Yalex sample chart, including the confirmed 4XL 24 × 31. For kids, only sizes 10 and 12 currently match the supplied chart; other priced sizes remain “To be confirmed” and editable. Actual product photos are still to be supplied.

Admin/staff dashboards show Confirmed Clothing sale, Average Order Value, best sellers, inventory, recent orders, and Sales over time with Daily, Weekly, Monthly, and Yearly views. The sales chart has an inclusive date range, an accessible line graph, and exact figures in a table. Best sellers are grouped by stable product IDs, so renaming a product keeps its historical units together; order details retain their original snapshots. The inventory preview puts the most urgent shortages first and links to the full paginated list, searchable by product, SKU, size, and color, with product and out-of-stock filters. Sales & Growth Forecast and Operations overview have separate pages. Customer management, packing, CSV export, and chatbot FAQ settings remain available. Staff can manage fulfillment/reports and inspect refund requests; catalog/customer/settings edits, refund decisions, and attaching previously unknown checkout references require admin. Categories and reserved quantities are absent from the interface; internal payment stock reservations still prevent overselling.

Uploads accept JPEG, PNG, and WebP only: product/variant photos up to 5 MB and account photos up to 2 MB. Files use generated names under public/uploads; executable uploads are blocked. Keep uploaded files backed up and preserve them across deployment. This project's Apache settings allow a 6 MB file/8 MB request; configure equivalent limits on other hosts. Images and personal data are excluded from Git.

PayMongo hosted Checkout v2 adapter, signed webhook verification, amount/currency/session/reference/mode checks, duplicate protection, locked inventory reservations, and payment recovery when a webhook is missed. Returning from checkout never confirms payment. Unconfigured checkout is disabled. Valid provider payment timestamps are used for sales reporting when supplied; a missing timestamp uses the confirmation time, and an invalid supplied timestamp refuses recovery.

Lalamove sandbox HMAC adapter, delivery pin selection, exact-address quotations, re-quotation/booking after packing, tracking links, scheduled delivery synchronization, and staff booking recovery/rebooking. Store pickup works without delivery credentials.

Grok widgets use server credentials and scoped customer/staff context. Without a key, labeled basic FAQ/catalog/order answers work. Customers only access their own orders. AI cannot change orders/payments. Sizing, policies, and recommendations depend on approved store data.

Store settings now separates the FAQ library from store information. Admins can add, edit, activate/deactivate, and delete individual questions and answers, or upload a CSV using the downloadable template. Search/status filters and 12-entry pages keep the library manageable. Only active FAQs are supplied to the chatbot, including its basic mode when Grok is not connected; Grok receives at most ten relevant FAQ entries per question. Pricing, payment confirmation, and order status answers come directly from store facts even when Grok is connected. CSV imports validate all rows before saving, with a limit of 100 rows and 1 MB per file. Opening hours, pickup information, support contacts, and additional approved store notes have their own fields. Previous free-text guidance is preserved in the store note during the upgrade.

The admin-only Activity log records meaningful authenticated actions from the upgrade onward. It covers sign-in/out, registration, profile/password changes, cart/wishlist changes, order creation, staff fulfillment/payment/delivery recovery actions, refund requests/reviews/verification, catalog/FAQ/store information changes, and report exports. Filter by person, role, event, and date; results are paginated and displayed in Asia/Manila time. Failed validation and unchanged edits do not create entries. Logs contain actor snapshots and limited action details, never passwords, tokens, raw request bodies, customer addresses, or chat messages. Passive browsing and anonymous activity are not recorded, and the admin interface has no log deletion action.

The web interface adapts to mobile and Android phone browsers: visible storefront search, an accessible admin menu with account/sign-out access, larger touch controls, readable form fields, contained table scrolling, and a chat panel sized for small screens. No Android app installation is required.

The delivery monitor uses spacious summary cards, a status ring with an exact-count legend, and a horizontal status chart with staff guidance. It counts only paid Lalamove orders placed in the selected dates; pickups are separate. Every status opens matching orders while preserving the dates, payment confirmation and delivery-method filters. The page shows the current state of those orders, not historical status-transition events. On phones the charts stack vertically and a Delivery charts button jumps directly to them.

## Forecast formulas

Historical growth:

    growth rate = (last completed month sales - preceding month sales) / preceding month sales
    forecast = last completed month sales × (1 + growth rate)

Linear regression:

    y = a + bx
    b = sum((x - mean(x)) × (y - mean(y))) / sum((x - mean(x))²)
    a = mean(y) - b × mean(x)

Uses up to six completed months and excludes the current incomplete month from its inputs. x is 1..n; x=n+1 forecasts the current month. Covers confirmed clothing sales after the quantity discount and unit demand per active product. Each product has its own history, beginning in its catalog creation month or an earlier genuine paid-sale month for imported history. Months before introduction are excluded; zero-sale months after introduction remain part of the history. A product without observed completed-month paid sales is labeled as needing history rather than receiving a zero-demand prediction. Growth requires two periods with a nonzero earlier period; regression requires three. Negative demand predictions display zero. Historical Units sums pieces sold in the displayed product periods. Suggested Reorder is max(0, rounded-up regression demand minus available stock); it is a planning estimate, without supplier lead times or safety stock. The page reveals each product's periods, history basis, rate, intercept, slope, and target month. Backtesting on actual sales is still needed.

Confirmed Clothing sale sums paid order clothing subtotals after the bulk discount and excludes delivery fees. Average Order Value divides that same total by the number of paid orders. Sales periods use paid_at in Asia/Manila; weekly periods begin Monday. Without selected dates, sales cards and best sellers show all-time values while the chart shows the last 7 days, 8 weeks, 12 months, or 5 years. With both dates selected, the sales total, paid-order count, AOV, best sellers, chart, and CSV export use the same inclusive range. Customer totals remain lifetime counts and packing counts reflect the current queue. Current incomplete chart periods are labeled. Daily/weekly ranges allow at most 366 days; monthly/yearly ranges at most 10 years, with a maximum of 120 chart periods for every grouping. No future dates or estimated sales are accepted.

Operations overview tracks paid orders placed within its selected dates, their current fulfillment status, pickup/Lalamove counts, and payment/refund queues. It defaults to the last 30 days and supports Daily/Monthly trends with ranges up to 366 days. This is an order-placement view, distinct from the sales dashboard's payment-date view. Confirmed refunds use their provider confirmation date and appear as separate events and gross amounts. A refunded order remains in its current fulfillment status. Refund amounts may include delivery and are not automatically subtracted from Confirmed Clothing sale because a clothing/delivery refund allocation has not been recorded.

## Payment, delivery, and refund recovery

Customers can check or close their own pending checkout; staff/admin can do this for store orders. Closing a checkout requests provider expiry and then retrieves its status again. Stock is released exactly once only after PayMongo verifies an expired session with no paid or unsettled payment. Processing payments, missing/conflicting details, and network failures keep the reservation. If payment succeeded, recovery confirms the order and commits inventory instead. An owner can attach an unknown checkout reference after verifying the order, amount, mode, and that it is not attached to another order.

UNPAID_CHECKOUT_MINUTES defaults to 30, with a minimum of 15. The payment scheduler checks pending orders with known checkout references every five minutes and attempts verified expiry once the order reaches that age. The threshold is not a guarantee of release at exactly 30 minutes: uncertain provider states remain held for investigation. A checkout creation timeout without a known reference requires owner reconciliation; retrying blindly could create another checkout. Only after confirming in PayMongo that no payment or session exists can a sessionless reservation be released with:

    php artisan shop:release-reservation ORDER-NUMBER --confirmed-no-payment

Uncertain delivery bookings remain blocked from another booking. Staff/admin can verify a known delivery or attach the provider ID of an uncertain attempt; pickup/recipient details, coordinates, order metadata, mode, and existing ownership must match the saved booking. Verified CANCELED, REJECTED, or EXPIRED attempts are retained in delivery history, return the order to ready for handover, and allow a fresh quote/booking. Unknown statuses or failed checks preserve the existing booking. Booking does not proceed while an approved/processing refund or a full confirmed refund holds fulfillment.

Customers can request a refund on their own verified paid orders and view the decision/status. Staff/admin can review the queue and check attached references; only the owner/admin can approve/reject requests and attach refund references. Approval records an authorized amount and explanation. The owner processes the actual refund externally in PayMongo, then enters its reference here. This application verifies the refund through a provider GET request, checking payment, currency, mode, amount, reference ownership, status, and confirmation timestamp. Approval alone does not confirm money returned. There is no financial refund POST or automatic restocking; stock changes require physical return inspection and an owner inventory update. Refund requests are distinct from a complete returns/disputes workflow.

## Scheduler

For local development:

    php artisan schedule:work

In production, invoke Laravel's scheduler every minute from the hosting scheduler/cron, using the installed PHP binary and the absolute project path:

    * * * * * cd /absolute/path/to/better-zone-one-tees && php artisan schedule:run >> /dev/null 2>&1

The registered payment and delivery synchronization commands run every five minutes with overlap protection. Configure a shared cache for scheduler locks when running more than one application server and ensure one intended scheduler execution per minute. Without the scheduler, staff checks remain available but background recovery/tracking does not run. Queue workers will be needed if asynchronous jobs are added. Configure database/upload backups separately; these scheduled commands are not backups.

## Connect providers

Credentials belong in .env only; it is ignored by Git. Never put keys in chatbot FAQs.

PayMongo: PAYMONGO_MODE=test, test secret key, webhook secret. Register checkout_session.payment.paid at a reachable HTTPS URL ending /webhooks/paymongo. Localhost cannot receive provider webhooks; use a trusted tunnel or staging deployment. APP_URL must match. Only webhooks bypass browser CSRF. Verifier supports legacy/current send.webhook envelopes, configured test/live signature, and five-minute timestamp tolerance.

Lalamove: sandbox key/secret, actual pickup address/coordinates/phone, supported service type. Customers can tap the checkout map, drag its pin, or choose “Use my current location”; location permission is requested only when that button is pressed. Manual latitude/longitude entry remains available. Production browser geolocation requires HTTPS and user permission; localhost can be used for local testing. The pin and structured address must be checked together; automatic address geocoding is not implemented. Map tiles require internet access. Quotes expire in minutes, so booking requests a fresh quote. If its fee exceeds collected delivery fees, staff resolution is required. API wallet expenses and customer payments are separate.

Grok: GROK_API_KEY and GROK_MODEL available in your xAI account. No external request occurs until configured. Account model availability can vary.

External providers have not been end-to-end verified with your credentials. Automated tests exercise mocked provider responses, signed webhook payloads, recovery failures/races, role boundaries, date aggregation, and forecast history. Passing these tests does not establish real account eligibility, payment settlement/refund timing, Lalamove service coverage/wallet behavior, or Grok model access. Complete sandbox and controlled live verification before cutover. Browser responsive checks do not replace testing on physical Android devices, including GPS permission/failure, slow map loading, hosted checkout, and the on-screen keyboard.

Local revision checks on October 12, 2026: 306 automated tests / 2,478 assertions passed with SQLite, all Blade templates compiled, and 230 focused tests / 1,548 assertions passed on MariaDB 10.4.32. An independent two-process InnoDB test verified that competing six-piece checkouts against ten pieces of stock produce exactly one reservation. Customer, staff, and owner recovery flows were also checked in a separate browser simulation, including a 390 × 844 phone viewport. These figures describe the tested local revision; external provider calls were simulated.

The expanded delivery-chart revision passed the full suite on October 15, 2026: **311 tests / 2,608 assertions**, plus Blade template compilation. Its 19 Operations tests include exact status-link results, empty cohorts, delivery-method filtering, pagination and date boundaries. The earlier MariaDB and stock-contention evidence above remains the recovery revision baseline.

## Remaining before replacing the live store

- Configure provider credentials, public webhook HTTPS reachability, pickup details, and scheduler execution; verify payment success/expiry/recovery and delivery booking/recovery with the real sandbox accounts.
- Confirm product photos, remaining kids' measurements, stock counts, opening hours, support details, and the store's refund/physical-return procedures. Owner processing of refunds is currently external; disputes, return inspection, and clothing/delivery refund allocation still need a defined workflow.
- Password recovery/email verification, customer notification jobs, support escalation tickets, backup automation, and load testing remain.
- Test the deployed site on physical mobile devices and verify backups can be restored. Forecast accuracy needs historical-data backtesting.
- Replacing the earlier website still requires a reviewed catalog/customer/order migration, controlled provider live testing, acceptance, rollback, domain configuration, and HTTPS. This source update does not perform hosting or database cutover.

## GitHub / deployment

GitHub hosts source; the web store needs a PHP/MySQL host. Commit source/composer.lock; never .env, vendor, customer data, database dumps. Included GitHub Actions runs tests and compiles templates.

Production requires APP_ENV=production, APP_DEBUG=false, SHOP_DEMO=false, a fresh secure APP_KEY, restricted database credentials, HTTPS/session cookie security, public document root, and verified provider live accounts. Remove/rotate demo users and replace sample inventory. PayMongo live keys require demo mode off plus HTTPS APP_URL. Lalamove production is blocked in demo mode. Configure trusted proxies and shared infrastructure for the host.

Deploy via Git, install dependencies, run reviewed migrations with php artisan migrate --force, and cache configuration/views. Public hosting/domain cutover has not been performed.

References: [PayMongo checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [webhook signatures](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [Lalamove](https://developers.lalamove.com/), [xAI API](https://docs.x.ai/developers/rest-api-reference/inference).

## Brand identity

Uses the supplied Zone One Tee’s logo and palette: Coffee Bean #220901, Dark Garnet #621708, Oxblood #941B0C, Rusty Spice #BC3908, Orange #F6AA1C, and White #FFFFFF. White backgrounds keep the shopping/admin layouts readable; garnet/oxblood serve controls, orange highlights, and rusty spice charts.
