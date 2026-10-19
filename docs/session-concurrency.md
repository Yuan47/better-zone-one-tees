# Browser session concurrency

Verified locally on 19 October 2026 with PHP 8.4.24 and Node 22.22.2.

Laravel reads and later saves a complete session snapshot for every web request. A read-only status check can therefore overwrite a cart change saved by another request from the same browser. Before correction, isolated PHP workers reproduced lost additions, lost quantity updates, restored hidden cart lines, and a status response overwriting a newer cart.

`config/session.php` enables session blocking for every web route. Laravel obtains the per-session cache lock before loading session data and releases it after saving the response. Different browser sessions use different locks. A request that cannot obtain its lock within the route's wait returns HTTP 503 with a short retry message and `Cache-Control: no-store, private`; it does not load or save the session.

## Lock durations and provider calls

The default lock lifetime and wait are 90 seconds. Existing Google and checkout routes retain their explicit overrides. The current provider-call budgets are:

| Route or operation | Maximum configured sequential provider time | Session lock lifetime |
| --- | --- | --- |
| Google start/link | No HTTP request; creates an authorization redirect | 30 seconds |
| Google callback | Two requests, 12 seconds each: token exchange and user information | 30 seconds |
| Customer checkout submission | One PayMongo creation request, 25 seconds | 40 seconds |
| Guest invoice payment, online installment, online walk-in | One PayMongo creation request, 25 seconds | 40 seconds |
| Staff cash payment | No provider HTTP request | 40 seconds |
| Payment check and close | Up to three requests, 20 seconds each: retrieve, expire, retrieve | 90 seconds |
| Courier booking | Quote and booking, 25 seconds each | 90 seconds |
| Delivery quote, refresh or recovery | One request, 25 seconds | 90 seconds |

Customer checkout submission validates the courier quote already saved in the session. It does not also request a fresh courier quotation. The separate quotation endpoint uses the 90-second default. PayMongo, Lalamove and Google calls on these paths do not automatically retry. Connection timeouts are part of each total request timeout.

These are provider-time budgets, with remaining time available for local work. A cache lease is not an execution deadline: database contention, unusually slow local work or a suspended PHP worker can exceed it. If a future route adds provider calls or retry loops, its lock lifetime must be reviewed again. This verification does not establish an upper bound for arbitrary database or infrastructure delays.

## Cache requirements and outage behavior

Session blocking uses Laravel's configured cache store, unless a dedicated `session.block_store` is configured. Every application worker must share a lock-capable store. The database store supports multiple application instances; file locks require a shared filesystem and are suitable for the single-host process regression. An in-process array store does not coordinate independent PHP workers and must remain confined to isolated tests.

An idle-state cache error refuses protected customer activity without extending its deadline. Explicit logout still invalidates the browser session when the browser-session lock backend is reachable. A total outage of that lock backend occurs before controller execution and can prevent logout and other web requests; the idle-state outage regression deliberately preserves the real browser-session lock while failing only the idle-state lock.

## Regression coverage

`tests/Feature/CartSessionConcurrencyTest.php` launches independent PHP workers through the actual Laravel `StartSession` middleware, using a fresh temporary SQLite cart fixture, file sessions and file cache locks. It covers different-line additions, same-line increments, quantity updates, removal versus update, hidden-line cleanup versus update, a read-only status check versus update, independent browsers, and HTML/JSON lock-timeout responses. The worker cannot target a directory outside its generated temporary fixture prefix. No store database or provider is used.

`tests/Browser/customer-session.test.cjs` verifies that trusted activity received during a pending status check is queued and acknowledged after the response, including **Stay signed in**. The acknowledgement retains the gesture's original age. Server-confirmed expiry wins over queued activity, and the queue does not introduce idle keepalive polling.

The focused PHP suite also exercises customer idle expiry, password session invalidation, Google sign-in, accounts and commerce with an in-memory SQLite database. Google transport and provider HTTP responses are simulated; provider credentials are disabled outside tests that supply fixture configuration. Logs and session files use an isolated verification directory.
