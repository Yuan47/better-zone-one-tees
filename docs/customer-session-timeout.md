# Customer inactivity sign-out

Customers are signed out after 900 seconds (15 minutes) without acknowledged activity. Staff and owner sessions retain the existing session lifetime. This does not expire a payment invoice, cancel an order, release stock, or change a due date.

The server checks the customer deadline before protected work. A page navigation or submitted HTML form renews an unexpired session. JSON/background fetches do not renew it automatically. JavaScript disabled, paused, or closed cannot bypass the server deadline: the next request is refused after expiry.

The customer browser observes actual visible pointer, keyboard, input, wheel and touch gestures. It sends at most one activity acknowledgement per minute per tab, only after an interaction; there is no periodic keepalive. A delayed acknowledgement includes a bounded 0–60 second age so waiting to send does not add a minute to inactivity. Programmatic scrolling, hidden tabs and synthetic events do not renew activity. The page warns during the final minute, offers **Stay signed in** and **Sign out**, and checks the server once at expiry. A suspended tab checks again when it becomes visible near or after its deadline. Requests time out after 10 seconds so the warning cannot remain stuck waiting forever.

Tabs share acknowledged deadlines and sign-out notices through same-origin browser storage/BroadcastChannel. No authentication cookie, CSRF value, password or email is stored in those messages. The server remains authoritative.

Customer idle state uses the configured Laravel cache with a short atomic lock and a random browser-session identifier. Keep a persistent, lock-capable cache configured: the existing database cache works for a shared deployment; the file cache works on one server. Do not use an in-process `array` cache for production or separate per-server caches across multiple application instances. Cache eviction falls back to the stored server-session deadline, rather than opening a fresh 15-minute window. Expired shared records prevent an older in-flight tab from reviving login. A cache/lock outage refuses protected work with a retryable error and does not extend the deadline; explicit sign-out remains available.

After a timed-out safe page request, a successful login returns to that page. A submitted payment, receipt, refund or other mutation is never replayed automatically. Signed guest invoice links and provider webhooks remain independent of customer login; online payment confirmation can still arrive after the customer signs out.

Before public deployment, verify warning placement and keyboard/touch behavior on the target Android devices, confirm the configured cache is shared by every application worker, and test a real hosted-checkout return after an idle sign-out.
