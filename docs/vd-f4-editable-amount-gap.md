# VD F4 — approve receipt with editable amount (D13-7) — handover

**Status:** ✅ backend shipped 2026-08-12 (task **B26**). FE F4 unblocked.
**Ref:** VD Item 5 · D13-7 · depends on B8 (approve/reject receipts).

## The gap (before B26)

`PaymentReceiptService::approve()` credited the **exact student-submitted** `amount_minor`
in both ledger legs, with no correction path. A student who fat-fingered the figure (or made
a partial transfer) could only be **rejected + asked to resubmit** — there was no way for a
reviewer to approve for the true amount. That blocked F4 "approve with editable amount".
Decision **D13-7** was originally resolved *as-is* (2026-08-05, MVP); B26 flips it to *correct*.

## What shipped

- **Migration** `2026_08_12_000001_add_corrected_amount_minor_to_payment_receipts` —
  `corrected_amount_minor` (`unsignedBigInt`, **NULLABLE**, after `amount_minor`).
  `amount_minor` stays the **student-submitted original** (audit baseline); the reviewer is
  already captured by `reviewed_by` / `reviewed_at`.
- **Service** `PaymentReceiptService::approve(PaymentReceipt $receipt, User $reviewer, ?int $correctedAmountMinor = null)`:
  - Omitted → credit the submitted `amount_minor`; `corrected_amount_minor` stays `NULL`.
  - Given → must be `> 0` and `≤ PaymentReceipt::MAX_AMOUNT_MINOR` (100_000_000 minor = 1,000,000 EGP),
    else `InvalidArgumentException`. The corrected value funds **both** ledger legs
    (`STUDENT_WALLET` credit + `GATEWAY_CLEARING` debit) and is stamped on `corrected_amount_minor`.
  - Idempotency unchanged: the credit posts once under op-key `receipt:{id}`; correction happens
    at the single first approve (status flips to `approved`), so a re-approve still `409`s via
    `guardPending`.
- **Request** `ApproveReceiptRequest` — optional `corrected_amount_minor` (`nullable|integer|min:1|max:100000000`),
  wired into `PaymentReceiptController::approve` (HTTP bad value → `422`).
- **Resource** `PaymentReceiptResource` now returns `corrected_amount_minor` (null when uncorrected).

## API (for the F4 screen)

`POST /api/v1/teacher/payment-receipts/{receipt:uuid}/approve` — gated `role:teacher,assistant` + `permission:finance`.

Body (optional):

```json
{ "corrected_amount_minor": 30000 }
```

Response `data`: `amount_minor` (submitted original) · `corrected_amount_minor` (reviewer value or `null`) ·
`status` · `reviewer` · … Show the submitted amount as the pre-filled, editable default; send
`corrected_amount_minor` only when the reviewer changes it.

## Tests

`tests/Feature/Wallet/PaymentReceiptTest.php` (12 green): default credits submitted + leaves
`corrected_amount_minor` null · corrected credits corrected + records original vs corrected +
both legs post corrected · corrected double-tap `409` idempotent · corrected `≤ 0` → `422`.
