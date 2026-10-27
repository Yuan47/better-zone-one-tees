# Customer Google sign-in

Current local acceptance is recorded in [Current integration checkpoint](integration-status.md). Real customer creation and admin-access rejection passed; remaining deployment and edge cases are tracked separately. The original preparation guidance below describes configuration requirements.

Prepared on 19 October 2026. This feature stays disabled until the Google OAuth web application is configured. No real Google account or credentials are included in the repository.

## Customer behavior

- When enabled and fully configured, **Continue with Google** appears on Sign in and Create account.
- A new verified Google identity creates a **customer** account, with its store email verified by Google's server response. It cannot create or grant a staff or owner role.
- An email matching an existing store account is **never silently linked**. The customer signs in with their current password, opens My Account, confirms that password, and chooses the Google account with the same verified email.
- Staff and owners retain email/password sign-in and the owner's OTP-based staff setup. Google cannot bypass a pending staff invitation or sign into a staff/owner account, even if an old customer identity remains linked after a role change.
- A Google subject (`sub`) identifies the linked account. Google profile names do not replace edited store profiles; Google photos are not fetched or stored.
- Store email remains fixed. If the linked Google account's verified email later changes, Google sign-in stops with recovery instructions. It does not relink by the new email, transfer orders, or change the stored email. The customer uses password sign-in or recovery through the original store email and contacts the owner if necessary.
- A Google-created customer initially has a random, undisclosed password. Existing email password recovery sets a first password after Brevo recovery is configured. Google linking cannot replace another linked Google identity; no unlink endpoint is provided.
- Successful sign-in rotates the session, retains the 15-minute customer inactivity protection, and displays existing payment alerts. A password change/recovery still invalidates older sessions.

## Configuration in the later integration step

1. Deploy the additive `google_identities` migration and install the locked Composer dependencies. Existing accounts and password login remain unchanged.
2. Create a Google **Web application** OAuth client and configure its consent screen/test users as applicable. Register the exact deployed callback: `https://YOUR-DOMAIN/auth/google/callback`. Local testing may use `http://localhost:PORT/auth/google/callback` with that exact registered URI.
3. Set `APP_URL` to the actual origin, then provide only in the private environment:

   ```dotenv
   GOOGLE_SIGN_IN_ENABLED=false
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_REDIRECT_URI=
   ```

4. Set the callback to the same origin and application path as `APP_URL`, with no query, fragment, username, or password. HTTPS is required except loopback local development. Configure trusted reverse proxies and secure session cookies for the deployed HTTPS site. Keep one stable session/cache store across instances; do not use the cookie-only session driver because the OAuth routes use session locking.
5. Refresh the application's configuration cache after changing settings. Enable the feature only after credentials, callback, and deployed database are ready. Configure Brevo password recovery before offering Google-created accounts an email/password fallback.
6. Verify consent success, cancellation, existing-account linking, a wrong Google account, expired state, duplicate callback, password recovery, and mobile navigation on the temporary domain. These real-account checks remain pending API configuration.

The Google client requests only `openid`, `email`, and `profile`; it does not request offline access or store access tokens, refresh tokens, or ID tokens. Provider exchange uses HTTPS server-to-server with bounded timeouts and redirects disabled. Identity comes from the access-token-authenticated Google userinfo endpoint, not from an unverified JWT or browser-submitted profile.

## Request safeguards and verification

Laravel Socialite 5.31.0 handles OAuth state, PKCE S256, authorization-code exchange, and Google userinfo retrieval. The application adds a 10-minute session/user/password-bound flow context and an atomic shared-cache single-use claim, checks `email_verified === true`, validates identity fields, and requires customer ownership and password confirmation for linking. Unique subject and user indexes prevent duplicate identity mappings; user email remains uniquely constrained. Database races fail safely with a retry message.

Start and link actions require POST plus CSRF, and all OAuth routes are rate limited and session locked. Cancellation, invalid state, invalid token/userinfo, and provider failures return a safe local sign-in/account message. Callback responses disable caching and referrers. A privacy middleware removes callback query parameters before Laravel records the previous page URL, including rejected callbacks. Deployment access/error monitoring should redact OAuth callback query strings; authorization codes necessarily arrive in the browser's redirect URL.

Automated tests mock **Socialite's actual Guzzle transport** with a MockHandler, with no fallback network handler. Laravel HTTP calls are also prevented from escaping. The test suite explicitly blanks/disables Google environment credentials by default, then the Google tests inject fake configuration. Assertions cover sessions and inactivity, provider failures, state expiry/replay/races, customer-only registration/linking, unchanged roles and profiles, pending staff setup, password changes, database uniqueness, CSRF, and secret handling. Full real-provider acceptance is a separate later step.

Official references:

- [Laravel Socialite](https://laravel.com/docs/13.x/socialite)
- [Socialite Google provider and package source](https://github.com/laravel/socialite/tree/v5.31.0)
- [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect)
- [Google OpenID Connect endpoint reference](https://developers.google.com/identity/openid-connect/reference)
