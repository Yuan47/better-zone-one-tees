# Concurrent stock, payment and refund verification

Verification dates: baseline on 18 October 2026; final financial-source verification on 19 October 2026 (Asia/Manila).

The final run passed **21 simultaneous-request cases: seven scenarios, repeated three times**, with no failed invariants. Each case ran two independent PHP processes against the actual Laravel services or signed webhook endpoint using synthetic data in a disposable MariaDB database.

This is evidence about the tested software safeguards. It is not live PayMongo acceptance, a performance benchmark, a customer usability study, or proof that every possible defect has been eliminated.

## Method and isolation

The engine was MariaDB 10.4.32 with its actual `REPEATABLE-READ` isolation. A generated schema and a restricted schema-only account were created on the existing preview port 3307. Workers were explicitly denied access to the real store schema. The original XAMPP database on port 3306 was not used. The verification harness did not restart the database service; the dedicated preview service had been safely started separately before the final run.

Before testing, the real store received a read-only logical backup. All **26 real store table data and schema checksums remained unchanged** after the final race run. No store restore or reset was performed.

A separate controller connection held the relevant invoice or last-unit variant row lock. Both workers booted separately, then entered the real application path. The controller released its lock only after MariaDB showed both workers executing competing `FOR UPDATE` queries. The cash-versus-online scenario also controlled queue order: online first in rounds 1 and 3, cash first in round 2. This exercised both outcomes while the competing request was already waiting.

Provider HTTP requests were simulated, and unexpected requests were blocked. No real payments, refunds, email messages or courier bookings were issued. The refund cases used the actual immutable return-inspection service, rather than disabling inspection requirements.

## Defects reproduced before correction

The authoritative baseline contained 18 cases: six scenarios repeated three times. Six cases failed, representing two reproducible defects:

- A duplicate signed PayMongo webhook could receive HTTP 500 because a stale event lookup attempted to insert the same unique event identifier. The financial and stock changes were still applied once, but the acknowledgement was unreliable.
- Cash balance recording and creation of an online balance checkout could both succeed, leaving an active provider checkout on an invoice already paid in cash. A later external charge would require reconciliation.

Both involved ordinary reads establishing an older MariaDB snapshot before the invoice lock was obtained. The corrections preserve the credit-lock order, use current locking reads for payment-reference and active-checkout checks, and resolve the initial webhook payment lookup before the transaction so the invoice lock protects the current event lookup.

The new return-inspection migration also initially failed on MariaDB because several required `timestamp` columns received incompatible implicit defaults. The required inspection dates were changed to `dateTime`, and the finalized migration passed on a fresh disposable MariaDB schema.

## Final results

| Simultaneous scenario | Required result | Repeated result |
|---|---|---|
| Two checkout requests reserve the last available unit | One reservation succeeds; the other is rejected; stock is not oversold | 3/3 passed |
| Same signed PayMongo webhook arrives twice | Both acknowledgements return HTTP 200; one event and one financial/stock application | 3/3 passed |
| Same verified installment receipt is applied twice | Calls are idempotent; invoice and stock are updated once | 3/3 passed |
| Cash balance recording races an existing online payment confirmation | Cash cannot bypass the active checkout; the online balance is applied once | 3/3 passed |
| Cash balance recording races creation of a new online checkout | Exactly one method is accepted; no active checkout remains on a cash-paid invoice | 3/3 passed, both queue orders exercised |
| Same cash receipt reference is submitted twice | Both calls return the same receipt; the balance and stock are applied once | 3/3 passed |
| Two verified refunds compete for the remaining invoice/receipt value | One succeeds; the excessive second refund is rejected; the original receipt, invoice and inventory remain unchanged | 3/3 passed |

Installment checks also asserted that the original deadline remained unchanged and received money never exceeded the invoice. Refund checks asserted the remaining paid amount and required a real recorded inspection with reuse of the same inspection for the competing receipt requests.

## Evidence and provenance

The starting published revision was `e79be1a354a6da8f1228fa6fe42354d59864e32c`. Because corrections were tested before their release commit, SHA-256 fingerprints identify the exact application files used by each baseline and final run. Every worker recorded those fingerprints, and each run confirmed the critical source files remained unchanged throughout execution.

The prepared sanitized evidence artifact is `concurrency-verification.json`; its SHA-256 is `a24cec100a70140b35361686031f2bb5fa27a4700fd0b873b32441d380f31e7c`. It contains per-case invariants, worker outcomes, application source fingerprints, harness fingerprints, and checksums of the original verification artifacts. Every worker's source fingerprints were checked against its run's starting fingerprints. It excludes account passwords, private store rows, logical backups, database account names, and provider secrets.

The original local baseline artifact is `results-baseline-corrected.json`; the final artifact is `results-final-20261019.json`. The final run includes the completed return-inspection allocation rules and the final financial source fingerprints. Exploratory synchronization timeouts and an earlier invalid synthetic refund timestamp are retained as diagnostic history and are not counted as application failures in those authoritative results.

Real-provider sandbox acceptance, deployed webhook retries, hosting backup recovery, and measured customer/staff/owner usability remain separate verification activities.
