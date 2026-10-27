# Customer and store workspace sign in

Customers use `/login` and may create a customer account at `/register`. When configured, Google sign-in is available only for customer accounts. Normal email/password registration continues to require the configured email verification code.

Existing store owners and staff use `/admin/login` with their email and password. The owner creates staff accounts through the protected Staff accounts page and completes the existing emailed OTP process. There is no public staff or owner registration page. Staff cannot create their own privileged account.

Both sign-in pages use the existing Laravel `web` guard, sessions, saved account roles and password recovery. The server accepts only customer credentials at `/login` and only staff/owner credentials at `/admin/login`. Choosing a page or submitting a role field cannot grant permissions. Customer accounts remain forbidden from the workspace, and staff retain the existing owner-only restrictions.

One browser session holds one signed-in account. Visiting the other sign-in page shows a sign-out action and a link back to the current account; it never replaces the account automatically. After sign-out, the switch action opens the chosen sign-in page. Two pages on the same site do not create independent simultaneous sessions.

Protected workspace links send guests to `/admin/login`; protected customer links send guests to `/login`. After sign-in or email verification, only a same-origin GET destination appropriate to the saved account role is restored. Authentication pages, external URLs and owner-only destinations for staff are not restored.

The common forgot/reset-password pages provide links to both portals. A successfully validated reset returns customers to customer sign-in and staff/owners to workspace sign-in using the actual saved role. Failed or unknown recovery requests keep the existing generic response. Password changes still revoke other sessions, and customers retain the 15-minute idle timeout.

Local demo credentials, when explicitly enabled in local/testing mode, are shown only on their matching portal. Demo credentials are not shown in production. Real account data, passwords, roles and verification records are unchanged by this route separation.
