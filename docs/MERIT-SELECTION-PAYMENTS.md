# Merit, selection, payment and milestone notifications

This module extends the existing admissions state machine; it does not replace applications, review, eligibility, documents, fees, payments, allocations or enrollments.

## Workflow

1. Applicant submission creates an application number and sends the **received** portal/email milestone.
2. Staff starts review and the existing eligibility/document/accounts desks complete their work.
3. **Verified for merit** is a strict server-side gate. It requires:
   - overall and programme blocking eligibility checks to pass;
   - every applicable required `application`-stage document to have status `verified`;
   - the immutable application-fee assessment to be paid, waived, or zero; and
   - no open correction request.
4. In **Admin → Merit & selection**, staff explicitly defines a programme formula. Class 10, Class 12 and entrance-percentile weights must total exactly 100%.
5. Generation creates a draft, versioned run. Formula version, component values, score, tie evidence and rank are snapshotted. It is not a live query.
6. Staff reviews then publishes one version. The older published run becomes `superseded` but remains preserved. Applicant results become private; the public list exposes application number and rank only.
7. Remaining ranked applicants are waitlisted. Staff selects one row or a batch. Every selection rechecks the currently published entry, application version, programme eligibility and live seat-matrix capacity in a database transaction.
8. Selection creates a seat allocation, admission-fee assessment and deadline-bound offer. The next waitlisted candidate is never auto-promoted.
9. The applicant pays through the one active online gateway or the always-available manual bank/UPI proof route.
10. Payment verification, admission and terminal outcomes continue through the existing workflow and audit trail.

Default deterministic tie order is higher Class 12 percentage, higher Class 10 percentage, earlier submission and then application number. The default reservation policy ranks every candidate on the General/open list first and also creates the eligible reserved-category list entry.

## Database and migration

Migration `006_merit_selection_payments` is additive. It adds cycle policy, immutable formula versions, runs, run-program snapshots, entries, selection offers, a retryable notification outbox, gateway configurations, transactions and hashed gateway event evidence. It does not drop or rewrite an existing admission row.

Before upgrade:

```bat
C:\xampp\php\php.exe scripts\admissions-preflight.php
C:\xampp\php\php.exe scripts\migrate.php --dry-run
C:\xampp\php\php.exe scripts\migrate.php --confirm=APPLY --backup-confirmed
```

Review `database/migrations/006_merit_selection_payments.rollback.md` before any rollback. Published rank, offer and financial evidence must not be deleted.

## XAMPP scheduled tasks

Create both tasks in Windows Task Scheduler under a restricted service account. Set **Start in** to the project directory.

Every 10–15 minutes:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\expire-admission-offers.php
```

This expires only still-unpaid `payment_due` offers and releases allocations. It does not promote anyone.

Every 2–5 minutes:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\netaji-hub\scripts\process-admission-notifications.php
```

This drains up to 500 queued milestone emails, retries transient failures with backoff, and recovers stale worker claims. Portal notifications are written immediately.

Monitor non-zero task exit codes and the Admin email log. SMTP must be configured before production mail delivery.

## Online payment configuration

Go to **Admin → Settings → Payments**. Configure any of Razorpay Payment Links, Cashfree Payment Links or PayU Hosted Checkout, but make exactly one enabled gateway active. Secret and webhook values are AES-256-GCM encrypted and never rendered back into HTML or included in audits.

Register these HTTPS webhook URLs with the providers:

- `https://your-college.example/payments/gateway/razorpay/webhook`
- `https://your-college.example/payments/gateway/cashfree/webhook`

The adapters follow the official provider contracts:

- Razorpay Payment Links create/callback signature: <https://razorpay.com/docs/api/payments/payment-links/create-standard> and <https://razorpay.com/docs/us/payments/payment-links/apis/>
- Cashfree Payment Links and signed webhooks: <https://www.cashfree.com/docs/api-reference/payments/latest/payment-links/create> and <https://www.cashfree.com/docs/api-reference/payments/latest/payments/webhooks>
- PayU Hosted Checkout request/reverse hash: <https://docs.payu.in/reference/_payment_payu_hosted_checkout>

The application never trusts an applicant-submitted amount or browser-only success flag. Razorpay and PayU returns are signature checked; Cashfree returns are verified through a TLS-verified server API request; Razorpay/Cashfree webhook signatures and amounts are checked. The immutable fee assessment is locked and rechecked before a payment and receipt are created.

Real provider status remains **NOT TESTED** until the deploying institution supplies sandbox credentials, registers HTTPS return/webhook URLs, exercises successful/failed/duplicate/tampered transactions, and reconciles the provider dashboard against application receipts. Keep the manual fallback configured throughout provider testing.

## Notifications

Portal plus email milestones cover received, under review, correction requested, verified, merit published, waitlisted, selected/payment due, payment received, payment verified, offer expired, admitted and not selected. Bulk publication/closure and scheduled expiry queue email instead of attempting thousands of SMTP deliveries in one request.

## Operational checks

- Formula weights total 100% for every active cycle programme.
- Required document rules correctly reflect programme/category conditions.
- Seat categories and quota values match the merit policy and seat matrix.
- Offer validity and Windows scheduled tasks are configured.
- Public merit output contains no name, email, mobile, marks or profile evidence.
- SMTP outbox and mail logs are monitored.
- Gateway mode is `sandbox` until reconciliation testing is signed off.
