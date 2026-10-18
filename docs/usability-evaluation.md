# Usability and operations evaluation

**Status: protocol prepared; participant results and old-site measurements have not been collected.** This document does not establish a usability rating or a measured improvement. Use [usability-results-template.csv](usability-results-template.csv) to record actual observations.

## What this evaluation can demonstrate

Compare the previous Zone One Tee's workflow with the revised system for the same business outcome: placing an accurate order, verifying payment, preparing clothing, finding an invoice, handling a return, and producing a correct report. Record customer, staff, and owner results separately. Passing automated tests supports specific software rules; it does not prove that people find the website easy to use or that a real email, payment, or courier service works.

The store's goal is an overall rating of at least 8 out of 10, especially for reliability. This is a target to test, not a rating to assign in advance. A rating cannot override an incorrect payment, inventory, refund, or access-control result.

## Before participants start

1. Agree on the tasks, correct outcomes, time limits, and pass criteria before viewing results. Record the build identifier, test date in Asia/Manila, device, browser, and provider configuration.
2. Use an isolated copy of the new system and a safe copy of the previous website or its documented manual process. Do not overwrite real orders or experiment against live customers. Use synthetic products, customer details, payment evidence, and private test email accounts. Use provider test mode or simulation; do not charge, refund, or book a courier with real money for this study.
3. Prepare equivalent starting data for both workflows: the same available quantities, prices, customer, order amount, payment evidence, report period, and expected totals. Give each participant a fresh fixture. An earlier participant must not change a later participant's task.
4. Ask the owner to confirm how the previous process actually worked. For manual verification, record the steps used to inspect payment evidence, update the order, adjust stock, prepare the parcel, and calculate sales. Do not assume the old website had or lacked a feature. Keep a dated baseline workflow sheet with the owner's confirmation.
5. Recruit actual intended users with consent. A practical initial study may include at least five customers, two staff members, and the owner; report the actual number and experience level. This small convenience sample supports descriptive findings, not claims about all users. Participation is voluntary; feedback does not affect employment or service.
6. Assign anonymous IDs such as C01, S01, and O01. Keep names and consent records separately with restricted access. Exclude passwords, OTPs, private invoice links, customer addresses, and provider secrets from shared recordings and screenshots.
7. Give the same short introduction and practice task to each participant. Alternate which system is used first when possible to reduce learning effects. Record the order. Let users choose normal mobile or desktop devices, but use the same device for each paired comparison.
8. Test keyboard navigation, readable mobile text, form labels, error recovery, and page scrolling. Include an Android-sized viewport and an actual Android device when available. A resized desktop browser alone is not proof of Android usability.

## Task cards

Read the task goal without explaining which buttons to press. Record the old process, including an actual manual workaround where it exists. If the old workflow cannot achieve the outcome, mark it `unsupported` and report the coverage gap; do not invent an old completion time. Refund and forecast tasks are acceptance goals for the relevant completed revisions, rather than a claim that every safeguard is already implemented.

| ID | Role | Task goal | Correct outcome and evidence |
| --- | --- | --- | --- |
| C01 | Customer | Find a specified shirt, choose its color and size, favorite it, and place a fully paid pickup order. | Correct variant and quantity, correct quantity-price tier, one invoice and one verified test payment, order visible in history. Save the anonymized invoice and expected pricing calculation. |
| C02 | Customer | Make a qualifying installment order above PHP 1,000 after clothing discounts, find the balance and due date, then settle it. | Original invoice and due date remain unchanged; receipts total the invoice; no duplicate order. Attempt a second installment order while the first remains outstanding and explain the displayed restriction. A separate full-payment order remains available. |
| C03 | Customer | Find an earlier receipt and the delivery stage; confirm receipt when eligible. | Participant identifies the right order and payment record; delivery confirmation follows the required review and receipt rules. An unpaid or ineligible order cannot be closed as delivered. |
| C04 | Customer | Request a return of specified clothing from a delivered, fully paid order and find its progress. | Customer submits the reason, requested amount and optional private photo, and can identify the request deadline. The owner records the actual returned items and quantities during inspection. Participant understands that a request or approval is not proof of money returned. |
| S01 | Staff | Verify a test payment using the previous manual process, or identify the corresponding provider-confirmed payment in the new process, then prepare the same order. | Payment matches the invoice before packing. Clothing, quantities, and stock are correct. Record manual verification steps and active handling time separately from simulated/provider waiting time. |
| S02 | Staff | Record a walk-in sale for an existing account and a separate unregistered customer. | Right customer linkage, correct prices, payment-received confirmation for cash, one receipt per actual payment, and completion only after full settlement. Registered history and guest receipt access are correct. |
| S03 | Staff | Find an order whose courier booking needs checking and recover it using the allowed process. | Existing booking is checked before any rebooking; no duplicate courier request. Participant identifies packing, ready for delivery, with courier, and delivered correctly. Record any baseline telephone or manual workaround. |
| S04 | Staff | Find a returned order, review its request and inspection status, and identify the owner action needed. | Staff can review the request and private evidence but cannot save an inspection, approve a refund, or record returned money. The owner records the physical inspection. Stock changes only through the authorized inventory process after physical verification. |
| O01 | Owner | Produce sales and outstanding-payment figures for a specified date range. | Report reconciles to an independently prepared ledger: paid clothing sales, deposits/balances, confirmed refunds, and delivery fees are distinguished. Record the exact date basis and report labels. Compare the old spreadsheet/manual calculation with the new report. |
| O02 | Owner | Review the inspected return and complete a valid test refund, then try an excessive amount or reused reference. | Decision requires the current return-policy and inspection rules. Valid amount binds to its original payment receipt; invalid amount/reference is rejected. Only confirmed provider refunds or recorded returned cash count as completed; original receipts remain traceable. |
| O03 | Owner | Explain a sales forecast and decide what to do for a new product without sufficient history. | Participant identifies the target period, historical inputs, formula, and units, and recognizes insufficient-history warnings. Compare any actual previous planning method; do not treat confidence in a forecast as measured forecast accuracy. |
| O04 | Owner | Find who changed an operational record and explain an attempted action outside a staff member's authority. | Activity evidence identifies the actor, action, time, and reason where required. Access checks reject the unauthorized action. Never ask participants to guess a password or access another person's real account. |

Fix each task's time limit during a pilot and freeze it for the formal comparison. A failure or timeout remains in the results; do not silently rerun it until it succeeds. Record a later repeat as a separate run after documenting the change.

## Observe and record consistently

Start the timer when the participant begins the task after hearing the goal. Stop when the correct result is achieved or the agreed limit is reached. Record interruptions and any waiting time explicitly. Count an error as a distinct user action that causes a wrong result, rejection, or recovery; do not count background requests, normal thinking, or repeated display of one error as new errors. Use the same definition in both systems.

Use these `result_status` values:

- `not_run`: planned row only; measurements stay blank.
- `completed`: correct outcome without moderator help.
- `assisted`: correct outcome, but a moderator hint or intervention was needed.
- `failed`: incorrect result or the user abandons the task.
- `timeout`: task limit reached without the correct outcome.
- `unsupported`: baseline workflow cannot achieve this outcome; record the missing capability and any actual workaround separately.
- `not_applicable`: task does not apply to this participant; explain why.

For an authorized denial test, being correctly blocked is the successful outcome. A payment recorded twice, stock sold below its allowed availability, refund exceeding its source, or unauthorized financial change is a **critical issue**, even when the screen looks normal. Save the minimal anonymized evidence, pause that scenario, and report the issue. Do not compensate for critical failures with a high satisfaction rating.

The CSV contains blank planned rows for the same tasks in both environments plus one `SURVEY` row per role/environment. Duplicate these for each actual participant and repeat. Use `legacy` and `revised` in `environment`. Record timing and error observations on task rows; record ratings only on that participant's `SURVEY` row to avoid double counting. `not_run` rows are never included in calculated results. The expected outcome is defined in the task card; write the observed outcome in the CSV without personal data.

## Calculations

Let **attempted tasks** mean `completed + assisted + failed + timeout`. Report unsupported and not-applicable counts alongside every success rate so that missing old-system features are visible.

| Measure | Calculation | Interpretation |
| --- | --- | --- |
| Independent task success | completed / attempted tasks x 100 | Percentage correctly completed without help; provide numerator and denominator. |
| Assisted completion | assisted / attempted tasks x 100 | Shows where guidance is still needed. |
| Tasks with an error | attempted tasks with error_count > 0 / attempted tasks x 100 | Percentage of tasks with at least one observed error. |
| Errors per task | total observed errors / attempted tasks | Error count per attempt; not a percentage and not an error probability. |
| Completion time | Median elapsed seconds for independently completed tasks | Report sample count, range, timeouts, and assistance separately; do not drop failures without disclosure. |
| Paired time saving | (old seconds - new seconds) / old seconds x 100 | Calculate only for the same participant and comparable task completed independently in both systems, with positive old time. Summarize the paired values and counts; negative means slower. |
| Critical issue count | Count of confirmed critical issues, with scenario IDs | Describe each issue, fix, and retest; zero observed is not proof that none exist. |

Do not compare real provider delays on the old site against instantaneous mocked responses on the new site. For manual verification and parcel preparation, report both active staff effort and end-to-end elapsed time. State whether synthetic payment evidence, simulations, or provider sandbox records were used. Measure live-provider reliability separately during configured integration acceptance.

## Short feedback form

After each environment, ask participants to rate these statements from **1 = strongly disagree** to **5 = strongly agree**:

1. I could find the action or information I needed (`ease_1_to_5`).
2. The instructions and status messages were clear (`clarity_1_to_5`).
3. I could recover from mistakes without confusion (`recovery_1_to_5`).
4. I could see enough information to understand the outcome (`confidence_1_to_5`).

Allow a blank response if a participant did not encounter error recovery. Ask a separate question: **"Overall, how would you rate this system for your store tasks, from 0 to 10?"** Record this in `overall_0_to_10`. Ask what was hardest and what should change. These are project-specific questions, **not SUS, a validated scale, or objective reliability measurements**. Report each question's count, median or mean, distribution, and comments. Do not combine the five-point questions into an invented validated score.

The 8/10 goal can be evaluated against the direct overall question: **mean overall rating = sum of submitted 0-to-10 ratings / number of valid respondents**. Also report customer, staff, and owner results separately, plus the overall distribution and respondent counts. When respondents have different role counts, disclose that a combined mean gives more weight to the larger group. A single owner's rating represents that owner only.

## Business-value evidence for the defense

Maintain three distinct evidence sets:

- **Software checks:** automated tests and controlled scenarios for duplicate notifications, concurrent stock reservations, payment uncertainty, refund caps, permissions, and retry behavior. Record the tested build and actual result; test counts alone are not a reliability percentage.
- **Operational correctness:** independent ledger totals, returned-item quantities, stock movement records, and comparison with reports. Forecast accuracy requires chronological historical backtesting against actual outcomes; usability opinions cannot establish it.
- **Human performance:** the paired task results and participant feedback described above. Improvement claims require measured old and new results, with sample size and limitations.

For the previous manual process, collect actual time and error observations for checking payment evidence, finding/updating an order, preparing the parcel, and calculating a dated report. Preserve anonymized worksheets showing the correct answer. If no safe or comparable baseline can be measured, state **"baseline not measured"** and present revised-system results alone. Do not claim percentage time savings, fewer errors, greater sales, or an 8/10 result without observations.

## Defense completion checklist

- [ ] Build, test-data revision, device, provider mode, and date recorded.
- [ ] Owner confirms the previous manual workflow and correct ledger outcomes.
- [ ] Actual consenting participants complete customer, staff, and owner tasks.
- [ ] Both independent successes and failures/assistance/timeouts are retained.
- [ ] Unsupported baseline tasks and unequal test conditions are disclosed.
- [ ] Refund inspection and policy scenarios are run against the completed revision.
- [ ] Financial and inventory critical issues are fixed and independently retested.
- [ ] Forecast backtesting results are reported separately from usability scores.
- [ ] Email, scheduled jobs, webhook retries, backups/recovery, and mobile acceptance evidence are collected for the configured deployment.
- [ ] Findings include actual counts, formulas, anonymized evidence, unresolved limits, and next changes.
- [ ] Ratings and measured improvements are reported only after collecting results.

## Suggested report wording after the study

Fill in the actual values: "On [date], [number] customers, [number] staff and [number] owner(s) evaluated build [identifier]. Independent task success was [completed]/[attempted] for [role/environment]. Median comparable completion time changed from [old] to [new] seconds for [paired count] observations. [Count] baseline tasks were unsupported. Mean overall rating was [value]/10 from [count] respondents; role results were [values]. [Critical issues/remaining limitations] were recorded. Provider mode was [mode]. These findings describe the tested sample and conditions."

Leave every bracket unfilled until supported by recorded evidence.
