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

No frontend build step or external CDN is required. Uses Blade, CSS, JavaScript, and original SVG clothing illustrations.

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

Admin/staff dashboards show Confirmed Clothing sale, Average Order Value, best sellers, inventory, recent orders, and Sales over time with Daily, Weekly, Monthly, and Yearly views. Sales & Growth Forecast has a separate page. Customer management, packing, CSV export, and chatbot FAQ settings remain available. Staff can manage fulfillment/reports; catalog/customer/settings edits require admin. Categories and reserved quantities are absent from the interface; internal payment stock reservations still prevent overselling.

Uploads accept JPEG, PNG, and WebP only: product/variant photos up to 5 MB and account photos up to 2 MB. Files use generated names under public/uploads; executable uploads are blocked. Keep uploaded files backed up and preserve them across deployment. This project's Apache settings allow a 6 MB file/8 MB request; configure equivalent limits on other hosts. Images and personal data are excluded from Git.

PayMongo hosted Checkout v2 adapter, signed webhook verification, amount/currency/session/reference checks, duplicate protection, locked inventory reservations. Returning from checkout never confirms payment. Unconfigured checkout is disabled.

Lalamove sandbox HMAC adapter, exact-address quotations, re-quotation/booking after packing, tracking links, scheduled delivery synchronization. Store pickup works without delivery credentials.

Grok widgets use server credentials and scoped customer/staff context. Without a key, labeled basic FAQ/catalog/order answers work. Customers only access their own orders. AI cannot change orders/payments. Sizing, policies, and recommendations depend on approved store data.

Store settings now separates the FAQ library from store information. Admins can add, edit, activate/deactivate, and delete individual questions and answers, or upload a CSV using the downloadable template. Search/status filters and 12-entry pages keep the library manageable. Only active FAQs are supplied to the chatbot, including its basic mode when Grok is not connected; Grok receives at most ten relevant FAQ entries per question. Pricing, payment confirmation, and order status answers come directly from store facts even when Grok is connected. CSV imports validate all rows before saving, with a limit of 100 rows and 1 MB per file. Opening hours, pickup information, support contacts, and additional approved store notes have their own fields. Previous free-text guidance is preserved in the store note during the upgrade.

The admin-only Activity log records meaningful authenticated actions from the upgrade onward. It covers sign-in/out, registration, profile/password changes, cart/wishlist changes, order creation, staff fulfillment changes, catalog/FAQ/store information changes, and report exports. Filter by person, role, event, and date; results are paginated and displayed in Asia/Manila time. Failed validation and unchanged edits do not create entries. Logs contain actor snapshots and limited action details, never passwords, tokens, raw request bodies, customer addresses, or chat messages. Passive browsing and anonymous activity are not recorded, and the admin interface has no log deletion action.

The web interface adapts to mobile and Android phone browsers: visible storefront search, an accessible admin menu with account/sign-out access, larger touch controls, readable form fields, contained table scrolling, and a chat panel sized for small screens. No Android app installation is required.

## Forecast formulas

Historical growth:

    growth rate = (last completed month sales - preceding month sales) / preceding month sales
    forecast = last completed month sales × (1 + growth rate)

Linear regression:

    y = a + bx
    b = sum((x - mean(x)) × (y - mean(y))) / sum((x - mean(x))²)
    a = mean(y) - b × mean(x)

Uses up to six completed months. x is 1..n; x=n+1 forecasts the current incomplete month using finished history only. Covers confirmed net clothing sales and unit demand per active product. Growth requires two periods with a nonzero earlier period; regression requires three. Missing history is labeled. Negative demand predictions display zero. The forecast page reveals periods, rate, intercept, slope, and target month. Backtesting on actual sales is still needed.

Confirmed Clothing sale sums paid order clothing subtotals after the bulk discount and excludes delivery fees. Average Order Value divides that same total by the number of paid orders. Sales periods use payment confirmation timestamps in Asia/Manila; weekly periods begin Monday. The dashboard cards show all-time values, while the chart uses the selected period. Current incomplete periods are labeled.

## Connect providers

Credentials belong in .env only; it is ignored by Git. Never put keys in chatbot FAQs.

PayMongo: PAYMONGO_MODE=test, test secret key, webhook secret. Register checkout_session.payment.paid at a reachable HTTPS URL ending /webhooks/paymongo. Localhost cannot receive provider webhooks; use a trusted tunnel or staging deployment. APP_URL must match. Only webhooks bypass browser CSRF. Verifier supports legacy/current send.webhook envelopes, configured test/live signature, and five-minute timestamp tolerance.

Lalamove: sandbox key/secret, actual pickup address/coordinates/phone, supported service type. Destination coordinates are explicit in this first version; map/geocoding UX is pending. Quotes expire in minutes, so booking requests a fresh quote. If its fee exceeds collected delivery fees, staff resolution is required. API wallet expenses and customer payments are separate.

Grok: GROK_API_KEY and GROK_MODEL available in your xAI account. No external request occurs until configured. Account model availability can vary.

External providers have not been end-to-end verified with your credentials. Automated tests mock provider responses and signed webhook payloads.

## Remaining before replacing the live store

- Checkout timeouts retain order/reserved stock for reconciliation. Do not retry blindly. After confirming no payment/session exists, a sessionless reservation can be released with:

      php artisan shop:release-reservation ORDER-NUMBER --confirmed-no-payment

- Session-linked unpaid reservations need expiry/cancellation reconciliation; automatic expiry/refunds/disputes/replay tools are pending.
- Uncertain delivery bookings remain in delivery_booking to prevent duplicate bookings until reconciled.
- Password recovery/email verification, returns/refunds, customer notification jobs, support escalation tickets, map selection, backup automation, and load testing remain.
- Run the scheduler locally with php artisan schedule:work. Production needs schedule:run every minute, shared cache, queue workers as async features are added, and backups.
- Original website/database are untouched. Cutover requires catalog/customer/order migration, provider live testing, mobile acceptance, backups, rollback, domain configuration, HTTPS.

## GitHub / deployment

GitHub hosts source; the web store needs a PHP/MySQL host. Commit source/composer.lock; never .env, vendor, customer data, database dumps. Included GitHub Actions runs tests and compiles templates.

Production requires APP_ENV=production, APP_DEBUG=false, SHOP_DEMO=false, a fresh secure APP_KEY, restricted database credentials, HTTPS/session cookie security, public document root, and verified provider live accounts. Remove/rotate demo users and replace sample inventory. PayMongo live keys require demo mode off plus HTTPS APP_URL. Lalamove production is blocked in demo mode. Configure trusted proxies and shared infrastructure for the host.

Deploy via Git, install dependencies, run reviewed migrations with php artisan migrate --force, and cache configuration/views. Public hosting/domain cutover has not been performed.

References: [PayMongo checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [webhook signatures](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management), [Lalamove](https://developers.lalamove.com/), [xAI API](https://docs.x.ai/developers/rest-api-reference/inference).

## Brand identity

Uses the supplied Zone One Tee’s logo and palette: Coffee Bean #220901, Dark Garnet #621708, Oxblood #941B0C, Rusty Spice #BC3908, Orange #F6AA1C, and White #FFFFFF. White backgrounds keep the shopping/admin layouts readable; garnet/oxblood serve controls, orange highlights, and rusty spice charts.
