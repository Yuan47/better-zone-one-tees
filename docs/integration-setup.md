# Step 3: provider setup and acceptance

For the latest observed service results, see [Current integration checkpoint](integration-status.md). The original preparation record below describes the setup process, not the current activation state.

Prepared 19 October 2026 (Asia/Manila). Steps 1 and 2 are implemented. **Real provider acceptance remains pending credentials.** A private settings worksheet is outside this repository. It contains the authorized test recipient; the address and eventual keys must stay out of GitHub and shared evidence.

## Setup order and required values

| Provider | Values needed | Controlled first check |
|---|---|---|
| Brevo | API key, verified sender email and name | Send a single test verification message to the authorized owned inbox; record acceptance and actual inbox receipt separately |
| Google | Web application OAuth client ID/secret and exact callback | Sign in using the owned Google account; verify cancellation, fixed email and explicit existing-account linking |
| PayMongo | Test secret key, webhook signing secret, account-enabled methods | One synthetic test order, verified payment and receipt; then repeated/delayed notifications and reconciliation |
| Lalamove | Sandbox key/secret, supported service, pickup address/coordinates/phone | Sandbox quote, then controlled booking/status recovery; no production courier booking |
| GroqCloud | `GROQ_API_KEY` and `GROQ_MODEL` (default `openai/gpt-oss-20b`) | One general support question; verify fallback when unavailable and that approved policies/commerce facts remain deterministic |

The private worksheet is an **overlay**, not a replacement for Laravel's `.env`. Merge only reviewed integration settings into the intended test application. Preserve `APP_KEY`, `DB_*`, existing accounts, invoices, uploads, cookie names and storage paths. Back up the private environment first; never reseed the real store to prepare integration data. Provider keys are secrets; client screenshots and diagnostics must not expose them.

Lalamove booking now converts accepted local Philippine mobile contacts to international `+63` format for the provider, while preserving the original invoice's contact details. Courier checkout rejects an invalid recipient contact before reserving stock or starting payment. Invalid contacts at booking also stop before any quotation or booking request, returning the order to Ready without financial changes. Already international contacts retain their country code. This follows the provider's region-code requirement; it does not prove that a number belongs to the recipient.

## Safe local configuration checks

From the Laravel application directory:

```text
php artisan shop:check-integrations
php artisan shop:check-integrations --provider=brevo --json
php artisan shop:check-integrations --provider=google
php artisan shop:check-integrations --public
```

The command reads configuration only. It does not contact providers, check key validity, connect to the database, send email, create a checkout, book delivery, change flags, or prove public reachability. Exit 0 means its selected configuration checks pass; exit 1 means setup is still needed; exit 2 means an invalid provider option. Missing setup is expected until values are supplied. Output includes setting names and readiness, never keys or sender/recipient values.

The `--public` profile additionally requires HTTPS, debug/demo displays off, secure HTTP-only SameSite=Lax cookies, persistent session/cache configuration, and PayMongo test/Lalamove sandbox modes for the selected provider(s). These checks do not prove schema readiness, cache lock behavior, account permissions, safe passwords, or service acceptance. Verify those separately before exposure.

## Public callback address and ngrok

Use one exact testing origin. For `https://YOUR-TEST-HOST`, register:

```text
Google redirect URI: https://YOUR-TEST-HOST/auth/google/callback
PayMongo webhook:   https://YOUR-TEST-HOST/webhooks/paymongo
```

Set `APP_URL` to that origin and `GOOGLE_REDIRECT_URI` to its exact callback. The application supplies its own order return URLs; a browser redirect never proves payment. A hostname change requires reviewing registered callbacks and existing signed guest/payment-return links. Do not rotate `APP_KEY` as a workaround.

ngrok exposes the local server; GroqCloud supplies AI answers. They are separate services. For this machine the intended storefront upstream is `http://localhost:8081`, not the separate simulation on 8082. Starting a tunnel makes the selected site externally reachable: first prepare an isolated test copy, replace known demo credentials, confirm authorized real inbox accounts, and review public exposure before starting it. This preparation does not start a tunnel or register a webhook.

If the ngrok agent runs locally, set `TRUSTED_PROXIES=127.0.0.1,::1` in that test copy only. Leave it empty for direct localhost. On hosting use the documented proxy IPs/CIDRs; do not use wildcard trust. This configuration is loaded through Laravel's configuration cache. The application trusts forwarded HTTPS/host/port/client IP only from configured proxies and ignores forwarded path prefixes. Verify generated Google callback, checkout return and signed invoice URLs through the actual tunnel. The upstream and proxy must sanitize forwarded headers from untrusted clients; ngrok documents how its forwarded headers are appended. Do not assume an arbitrary forwarded HTTPS header proves a secure request.

Preserve the real public Host header where possible; check Apache selects the intended virtual host. If Host rewriting is necessary, follow ngrok's current instructions and verify the forwarded host and generated URLs. Do not guess a tunnel hostname or publish localhost callback URLs to providers.

For public testing use `APP_DEBUG=false`, `SHOP_DEMO=false` and `SESSION_SECURE_COOKIE=true`; retain HTTP-only cookies and SameSite=Lax for Google's cross-site callback. Configure stable session/cache storage with locking. Redact Google authorization-code queries and password-reset links from proxy/access logs and recordings. Do not use the seeded public demo passwords on an externally accessible instance.

## Email activation

The authorized personal inbox is a recipient, not automatically the sender. Configure a sender Brevo has validated; authenticate a sender domain that the store owns or controls. A hosting/ngrok temporary hostname does not provide control over its parent domain's mail DNS. Do not manufacture a sender under a domain the store does not control.

First establish real inbox delivery. Then activate verification, staff setup and password recovery individually and test the existing Laravel flows. Existing `@zoneone.test` demo accounts cannot receive mail: do not switch on mandatory verification for them or mark them verified to bypass inbox testing. Prepare legitimate role accounts through the controlled setup process before public acceptance.

Enable reminder sending last, on isolated synthetic invoices with controlled due dates. Test both 3-day and 1-day eligibility, duplicate scheduler runs, failures and uncertain responses. Preserve actual invoices' original due dates. `php artisan invoices:send-reminders --dry-run` previews eligibility without saving claims or sending messages. Brevo acceptance is not proof of inbox delivery; record both.

## Acceptance record

For every case record the date/time, build, provider mode, anonymized invoice/test case, expected outcome, observed result, provider reference, failure/recovery action and retest result. Keep private evidence outside Git; shared screenshots must exclude keys, OTPs, reset links, private invoice URLs, customer details and raw sensitive payloads.

- [ ] Brevo: verification, resend/expiry/reuse limits, password recovery and staff OTP, actual inbox delivery.
- [ ] Google: new customer, existing-account link, wrong account, cancellation, expired/repeated callback, mobile return, password recovery fallback.
- [ ] PayMongo: supported methods, complete and partial settlement, original invoice/deadline unchanged, receipt, failure/cancel/expiry, repeated/out-of-order notifications, provider reconciliation after interruption.
- [ ] Refund: delivered and fully paid invoice, immutable inspection, approved amount/receipt caps, actual provider verification, processing/failure/lower verified amount, no duplicated money or restocking.
- [ ] Lalamove: supported sandbox service, quote/expiry/changed cost, one booking, status refresh, uncertain booking reconciliation before rebooking, customer-only online Delivered confirmation.
- [ ] GroqCloud: approved FAQ precedence, accurate variant facts, private-order ownership, no financial actions, safe timeout/authentication/rate-limit fallback.

Creating provider credentials, changing provider permissions, starting a public tunnel, consent, and final financial/courier actions remain controlled operator steps. No production payment, refund or courier booking is required to establish sandbox acceptance. Temporary-domain operational checks and participant usability measurements remain Steps 4 and 5.

## Official references checked

- [Laravel trusted proxies](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies)
- [ngrok HTTP endpoints and forwarded headers](https://ngrok.com/docs/gateway/endpoints/http)
- [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Brevo transactional email API](https://developers.brevo.com/reference/send-transac-email)
- [Brevo sender/domain setup](https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC)
- [PayMongo hosted checkout quick start](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start)
- [PayMongo webhooks](https://docs.paymongo.com/reference/webhook-resource)
- [Lalamove API reference](https://developers.lalamove.com/)
- [Groq OpenAI compatibility](https://console.groq.com/docs/openai)
