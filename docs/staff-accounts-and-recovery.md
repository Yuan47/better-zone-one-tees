# Staff accounts and password recovery

## Owner creates a staff account

1. Sign in as the store owner and open **Staff accounts**.
2. Enter the staff member’s name, email, initial password and matching password confirmation. Choose a password of at least 10 characters. The owner should give the initial password to the intended staff member privately.
3. Save the setup. When email is configured and enabled, this requests a six-digit OTP through Brevo to the staff member’s email. A saved pending setup also has a resend action. The email does not include the initial password.
4. The staff member gives that code to the owner completing the expected setup. The owner enters it in the pending setup page.
5. Only a valid OTP creates the staff login, with the fixed **staff** role and verified email. Pending setups cannot sign in or recover a password because they are not user accounts yet.

Codes expire after 10 minutes. Five incorrect attempts disable a code; requesting a new code replaces the previous one. Resending requires a 60-second wait and is limited to five sends per hour. Completed codes cannot be reused. Owners cannot turn an existing customer or owner account into staff through this screen.

Pending passwords are stored as hashes and cleared when activation completes. Codes, passwords and reset links are excluded from activity-log details and form-session flashes. Staff setup and activation are recorded in the owner’s activity log.

## Forgotten password

Customers, staff and owners can choose **Forgot your password?** from the sign-in page. The request screen gives the same notice for registered and unknown email addresses, cooldowns and email-provider outcomes.

The reset link uses Laravel’s password broker: only a hashed token is stored, it expires after 60 minutes, and successful use consumes it. A new request after the 60-second cooldown replaces the old token. Brevo sends the link to the account’s registered email; link URLs use the configured `APP_URL`, rather than the browser’s Host header.

The user opens the link and enters a new password and matching confirmation. Passwords need at least 10 characters. The server also rejects unsupported password length and invalid characters before hashing. Resetting does not sign the user in or change email-verification status. It revokes the account’s existing database sessions and remember token, invalidates old email OTPs, and records the password reset. Orders, payment history, balances and inventory remain unchanged.

An already issued reset link can still work during an email-provider outage while recovery is enabled and the link remains valid. Requests do not automatically retry uncertain email sends. Provider acceptance means Brevo accepted the request; it does not prove that the message reached an inbox.

## Enable on the temporary domain

Keep these settings explicitly disabled while preparing the site:

```dotenv
STAFF_ACCOUNT_SETUP_ENABLED=false
PASSWORD_RECOVERY_ENABLED=false
```

Before enabling them:

1. Set the final HTTPS `APP_URL`, `APP_ENV=production` and `APP_DEBUG=false`. Preserve the existing `APP_KEY`; do not generate a replacement key for an existing deployment.
2. Configure `BREVO_API_KEY`, `BREVO_FROM_EMAIL` and `BREVO_FROM_NAME` using a verified Brevo sender. Keep credentials in the deployment environment, outside source control. These features use the transactional Brevo API, not Laravel’s mail transport setting.
3. Use database sessions, apply the pending migrations with `php artisan migrate --force`, then refresh cached settings with `php artisan config:cache`. Back up the existing database before migration.
4. Set `STAFF_ACCOUNT_SETUP_ENABLED=true` and `PASSWORD_RECOVERY_ENABLED=true` only when ready to send email, then refresh the configuration cache again. These flags are separate from customer email verification and payment reminders.
5. Verify one staff activation and password recovery using an email account you control. Confirm the sender, final HTTPS domain, inbox/spam delivery, expiration, single use, and sign-out of an older session. These acceptance checks require real configured email and are additional to the simulated tests.

Reset pages send `no-store` and `no-referrer` headers. Configure the hosting server, reverse proxy, analytics and error tracking to redact reset tokens and email query parameters from `/reset-password/*` URLs. Application response headers cannot remove sensitive URLs already collected by upstream access logs. Do not put reset URLs into screenshots, public issue reports or shared demo logs.
