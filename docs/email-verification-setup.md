# Email verification setup

This release prepares email verification through Brevo's transactional API. It is disabled locally until a real sender is ready. Enabling it requires customers, staff, and admins with an unverified email address to complete verification before using protected account features. Existing accounts are **not** automatically marked verified.

## Verification flow

- Registration sends a six-digit code when verification is enabled. An existing unverified account can sign in and request its code from the verification screen.
- The code expires after 10 minutes. Resending has a 60-second cooldown and a limit of five send requests per account per hour, including failed or uncertain sends. A challenge allows five incorrect guesses. A new code replaces the previous code.
- The browser submits the code to the backend. The database stores an HMAC digest and expiry/attempt/delivery metadata, not the plaintext code. Successful verification sets the account's `email_verified_at`; a code cannot verify another account or be reused after success.
- Opening or refreshing a page does not send an email. Codes are sent by a protected form submission and are not exposed in URLs, browser responses, application logs, or the admin interface.
- This verifies access to an email inbox. It is not two-factor sign-in or phone/identity verification.

## 1. Prepare the Brevo sender

1. In Brevo, add a sender using a real store-controlled address. Authenticate its domain using the exact Brevo code, DKIM, and DMARC records shown for that account. Preserve the domain's existing mail configuration; do not create duplicate DMARC records. Brevo only allows authentication of a domain that you control. [Official domain setup](https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC)
2. Complete sender validation and confirm the sender is available for transactional sending. Create a Brevo **API key**, not an SMTP password. [Sender management](https://developers.brevo.com/docs/sender-creation-and-management)
3. Check that Brevo permits transactional sending for the account and has sufficient sending allowance. Keep the key in the server's private environment configuration.

The website's temporary hostname and the email sender domain may differ. A temporary hosting or tunnel address does not give you control of its parent domain's DNS; authenticate a sender domain you actually own.

Brevo's authentication FAQ says SPF and MX records are not required **for domain authentication**. This does not replace inbound email hosting or a working reply/support mailbox. Keep existing MX/SPF records intact unless the email provider's setup specifically calls for a change. [Brevo authentication FAQ](https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC)

## 2. Prepare the temporary HTTPS deployment

Use the actual public HTTPS URL and point the web server's document root to `public`. Preserve the existing `APP_KEY`, database, uploads, and private configuration. Take a database backup before upgrading. Do not reseed demo accounts or regenerate an existing application's key.

Put these values in the server's private `.env`, using your actual hostname and validated sender:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-temporary-domain.example
SHOP_DEMO=false
SESSION_SECURE_COOKIE=true

# Keep off until the controlled activation test below.
EMAIL_VERIFICATION_REQUIRED=false
BREVO_API_KEY=
BREVO_FROM_EMAIL=
BREVO_FROM_NAME="Zone One Tees"

# Independent of account verification; leave off until reminders are approved.
BREVO_PAYMENT_REMINDERS_ENABLED=false
```

Fill the empty Brevo values on the server only. Do not put keys in Git, frontend JavaScript, screenshots, support messages, or form fields. The OTP sender calls Brevo directly over HTTPS; changing `MAIL_MAILER` or configuring SMTP is not required for this flow. The host needs outbound HTTPS access to `api.brevo.com`.

Apply the new migration and rebuild Laravel's caches from the application directory:

```sh
php artisan migrate --force
php artisan config:cache
php artisan view:cache
```

Check the public URL, HTTPS session cookie, login, and database connection before activation. With an HTTPS-terminating proxy or tunnel, verify the application receives the correct secure-request information through its trusted-proxy configuration. Do not disable secure cookies to work around a deployment issue.

## 3. Activate with a controlled test

Before enabling the flag, confirm that legitimate admin and staff accounts have reachable real inboxes. The seeded `@zoneone.test` addresses cannot receive mail. Existing account emails remain fixed in the user interface: a wrong address requires the store's controlled account-correction process, not entering a different email on the verification form. Correcting an address must not mark it verified without verification.

In a controlled deployment window, set `EMAIL_VERIFICATION_REQUIRED=true`, rebuild the configuration cache, and immediately test:

1. Register a customer using an inbox you control; confirm the verification screen appears and the email arrives.
2. Enter the received code; confirm protected account access becomes available and remains available after signing in again.
3. Try an incorrect code, an expired code, an old code after resend, and a reused code. Confirm rejection and the documented resend/guess limits.
4. Sign in with existing unverified customer, staff, and admin accounts. Confirm each must verify its own email and cannot bypass the check by entering a protected URL directly.
5. Confirm verified users can still place orders and use their role's allowed features. Confirm existing orders, payments, and installment deadlines are unchanged.

If the public hostname changes, update `APP_URL`, rebuild the configuration cache, and repeat the HTTPS/login test. Account OTP verification does not require an inbound Brevo webhook; payment-provider webhook setup remains a separate deployment task.

## Delivery outcomes and troubleshooting

Brevo's API uses `POST https://api.brevo.com/v3/smtp/email` with the `api-key` header. A `201` response with a message ID records provider acceptance; it does **not** prove the code reached the inbox. [Transactional API](https://developers.brevo.com/reference/send-transac-email) Brevo separately reports delivered, deferred, and bounce events. [Delivery events](https://developers.brevo.com/docs/transactional-webhooks)

For missing mail, check the correct inbox and spam folder, sender validation, domain authentication, account allowance, and Brevo's transactional logs. A timeout or uncertain response does not trigger an automatic retry; the user may request a new code after the cooldown. Use the newest email's code. Do not copy codes into diagnostics or mark accounts verified to bypass delivery failures.

Automated tests with a fake provider verify application behavior; they cannot prove sender approval, public DNS propagation, HTTPS/proxy configuration, Brevo acceptance, or actual inbox delivery. Complete the controlled real-inbox test before considering the deployed email flow accepted.
