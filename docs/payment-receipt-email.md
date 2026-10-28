# Store-issued payment receipt email

The invoice remains the authoritative payment history. Each verified PayMongo installment has its own received-payment reference and amount on the same invoice. A missing PayMongo email never justifies paying again.

The optional Brevo store receipt is an independent payment acknowledgment. It is not a PayMongo-issued receipt or tax invoice. Test-mode messages explicitly say that no real money moved. The notification saves its test/live display mode before sending and retains that label across a configuration change or a definitively rejected retry. Email acceptance is recorded as **Accepted by Brevo**, which does not establish inbox delivery.

Enable `BREVO_PAYMENT_RECEIPTS_ENABLED` only after the verified Brevo sender and a controlled inbox test. Also set `BREVO_PAYMENT_RECEIPTS_FROM` to the intended activation time in explicit ISO format such as `2026-10-28T12:00:00+08:00`. Invalid or missing cutoffs fail closed. The cutoff prevents unsolicited historical bulk mail when enabling the feature.

`payments:send-receipts` runs every minute through Laravel's scheduler. It discovers committed, non-legacy, paid PayMongo receipt rows independently of payment and webhook transactions. Pending attempts, unverified customer email addresses, cash receipts and inconsistent payment records are excluded. The scheduler must actually be running on the deployed host; defining its schedule alone does not verify execution.

One durable notification claim exists per payment attempt. No invoice, deadline, received amount, refund or inventory record is changed by email handling, and no payment/order lock is held while Brevo is called. Interrupted, uncertain or accepted sends are never automatically repeated. A later run resumes only a queued message whose transport has not been claimed.

For a customer-reported historical omission, a trusted server operator may select **one** verified payment with `--payment=<id> --reason="Customer reported a missing earlier store receipt."`. This is a reasoned single-receipt backfill, not a way to resend accepted or uncertain messages. `--dry-run` inspects eligibility without saving a claim or sending email.

Only a definitively rejected message may use the explicit `--retry-rejected` option together with one payment ID and a new reason after the sender setup is corrected. Timeouts, HTTP408, redirects, server errors, malformed provider acceptance and Brevo's `duplicate_parameter` idempotency response stay blocked for review. Brevo documents duplicate idempotency keys as suppressing a repeated send, so that response cannot prove the original request was rejected. Review Brevo's delivery evidence before taking any further action on an uncertain send; never delete its claim to resend it. See [Brevo's idempotency guidance](https://developers.brevo.com/docs/heterogenous-versions-batch-emails).

The customer receives only their saved account email, or the recorded contact email for an unregistered walk-in invoice. No recipient can be supplied through the command. The receipt contains its original reference, amount, date and balance after that installment, plus an authenticated or expiring authorized invoice link. Later payments and refunds remain separate invoice entries.
