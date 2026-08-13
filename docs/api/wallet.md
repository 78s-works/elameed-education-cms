# Wallet Module

The Wallet module (`app/Modules/Wallet`) owns each student's **wallet** and its
**append-only double-entry ledger** within a tenant. The student views their
balance and history, submits **manual top-up receipts** (Vodafone Cash / InstaPay,
VD R9), and staff with the `finance` permission review those receipts — approving
one credits the student's wallet (VD R10, corrected-amount support D13-7/B26).

## Overview

Every `(tenant, user)` pair has at most one `Wallet` (auto-created on first
access). A wallet's **balance is never stored** — it is derived on read from the
ledger as `Σ credits − Σ debits` over the wallet's `student_wallet` entries
(`LedgerService::balance()`).

`ledger_entries` is **append-only**: rows are never updated or deleted
(`LedgerEntry` disables `updated_at`). Money is only ever written through
`LedgerService::post()`, which inserts a set of **balanced legs**
(`Σ debits == Σ credits`) in a single transaction. Every post carries a
caller-supplied operation key, made unique per leg
(`{opKey}:{i}:{account}:{direction}`), so a replayed operation (e.g. a re-sent
Paymob webhook) posts nothing new — idempotency is enforced by a unique index on
`idempotency_key`.

Ledger writes originate from other modules (Commerce fulfilment credits
`teacher_earnings`/`platform_commission` and debits the funding source; wallet
top-ups credit `student_wallet`; codes/teacher adjustments post here too). The
Wallet module writes the ledger in **one** place of its own: **payment-receipt
approval** (`PaymentReceiptService::approve`) credits `student_wallet` + debits
`gateway_clearing`, idempotent on op-key `receipt:{id}`. Everything else here is
read-only.

## Models

| Model | Purpose |
|---|---|
| `Wallet` | A student's wallet in one tenant (`user_id`, `currency`, default `EGP`). Balance is derived, not stored. Has many `entries`. |
| `LedgerEntry` | An append-only double-entry row (`wallet_id`, `account`, `direction`, `amount_minor`, `ref_type`, `ref_id`, `idempotency_key`, `created_at`). No `updated_at`. |
| `PaymentReceipt` | A manual top-up receipt (`uuid`, `user_id`, `method` `vodafone_cash\|instapay`, `amount_minor`, `corrected_amount_minor`, `currency`, `attachment_id`, `status` `pending\|approved\|rejected`, `reviewed_by`, `reviewed_at`, `ledger_entry_id`, `reject_reason`). The student uploads proof; a `finance` reviewer approves (credits the wallet) or rejects. `attachment_id` points at the student's own [Engagement](engagement.md) `Attachment` upload. |

Service: `LedgerService` — the single place money is written; provides
`walletFor()`, `balance()`, `alreadyPosted()`, and `post()`.

**Accounts** (`LedgerEntry` consts): `student_wallet` · `teacher_earnings` ·
`platform_commission` · `gateway_clearing`
**Directions:** `debit` · `credit`

## Endpoints

All endpoints run inside the `tenant` middleware group and require an
authenticated, active member. The two balance/ledger reads and the two top-up
routes operate on the **current** student's wallet; the `/teacher/payment-receipts/*`
review surface adds `role:teacher,assistant` + `permission:finance`.

| Method | Path | Auth |
|---|---|---|
| `GET` | `/v1/wallet` | 👤 active member |
| `GET` | `/v1/wallet/ledger` | 👤 active member |
| `POST` | `/v1/wallet/topup/manual` | 👤 active member (`throttle:60,1`) |
| `GET` | `/v1/wallet/topups` | 👤 active member |
| `GET` | `/v1/teacher/payment-receipts` | 🧑‍🏫/assistant · `permission:finance` |
| `GET` | `/v1/teacher/payment-receipts/{receipt:uuid}` | 🧑‍🏫/assistant · `permission:finance` |
| `POST` | `/v1/teacher/payment-receipts/{receipt:uuid}/approve` | 🧑‍🏫/assistant · `permission:finance` |
| `POST` | `/v1/teacher/payment-receipts/{receipt:uuid}/reject` | 🧑‍🏫/assistant · `permission:finance` |

### `GET /v1/wallet`
**Purpose:** Return the current student's derived balance plus the 10 most recent
ledger entries.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | Yes (or `X-Tenant`) | `academy.elameed.app` |
| X-Tenant | Alt to Host | `academy` |
| Accept | Yes | `application/json` |
| Authorization | Yes | `Bearer 1|xxxx…` |

**Path / Query params:** None

**Request body:** None

**Response** — `200 OK`
```json
{
  "data": {
    "balance_minor": 5000,
    "currency": "EGP",
    "recent": [
      {
        "account": "student_wallet",
        "direction": "credit",
        "amount_minor": 5000,
        "ref_type": "order",
        "ref_id": 42,
        "created_at": "2026-07-15T09:12:04+00:00"
      },
      {
        "account": "student_wallet",
        "direction": "debit",
        "amount_minor": 15000,
        "ref_type": "order",
        "ref_id": 41,
        "created_at": "2026-07-14T18:03:22+00:00"
      }
    ]
  }
}
```

**Errors:** `401` unauthenticated · `403` inactive/suspended member ·
`422`/`404` tenant not resolved (no valid `Host`/`X-Tenant`).

### `GET /v1/wallet/ledger`
**Purpose:** Paginated full transaction history for the current student's wallet,
newest first (30 per page).
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | Yes (or `X-Tenant`) | `academy.elameed.app` |
| Accept | Yes | `application/json` |
| Authorization | Yes | `Bearer 1|xxxx…` |

**Path / Query params**
| Param | In | Required | Notes |
|---|---|---|---|
| `page` | query | No | Page number (default 1); 30 entries per page |

**Request body:** None

**Response** — `200 OK` (paginated `LedgerEntryResource` collection)
```json
{
  "data": [
    {
      "account": "student_wallet",
      "direction": "credit",
      "amount_minor": 5000,
      "ref_type": "order",
      "ref_id": 42,
      "created_at": "2026-07-15T09:12:04+00:00"
    }
  ],
  "links": {
    "first": "https://academy.elameed.app/api/v1/wallet/ledger?page=1",
    "last": "https://academy.elameed.app/api/v1/wallet/ledger?page=3",
    "prev": null,
    "next": "https://academy.elameed.app/api/v1/wallet/ledger?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 30,
    "to": 30,
    "total": 74
  }
}
```

**Errors:** `401` unauthenticated · `403` inactive/suspended member ·
`422`/`404` tenant not resolved.

### `POST /v1/wallet/topup/manual`
**Purpose:** Submit a manual wallet top-up (VD R9) — the student paid over Vodafone
Cash / InstaPay and uploads the transfer proof. Creates a **`pending`**
`PaymentReceipt`; **no wallet credit** happens until a `finance` reviewer approves.
**Auth:** 👤 Authenticated · **Middleware:** `tenant`, `auth:sanctum`, `active`, `throttle:60,1`

**Request body** (`SubmitManualTopupRequest`)
| Field | Rules |
|---|---|
| `method` | required, one of `vodafone_cash` \| `instapay` |
| `amount_minor` | required, integer, min 1 (minor units / piastres) |
| `attachment_id` | required, uuid — must be an [Engagement](engagement.md) `Attachment` **uploaded by the caller in this tenant** (proof of transfer) |

**Response** — `201 Created` — a single `PaymentReceiptResource` (`status: pending`,
`attachment` loaded).
**Errors:** `422` validation (bad method/amount, or an attachment that isn't the caller's own).

### `GET /v1/wallet/topups`
**Purpose:** The student's own manual top-up receipts + their status (VD F3), newest
first (30/page).
**Auth:** 👤 Authenticated · **Middleware:** `tenant`, `auth:sanctum`, `active`

**Path / Query params:** `page` (default 1; 30 per page).
**Response** — `200 OK` — paginated `PaymentReceiptResource` collection.

### `GET /v1/teacher/payment-receipts`
**Purpose:** The reviewer queue (VD R10) — every manual top-up receipt in the
academy, newest first (30/page). Tenant-level, **not** year-scoped.
**Auth:** 🧑‍🏫/assistant · **Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:finance`

**Path / Query params**
| Param | In | Required | Notes |
|---|---|---|---|
| `status` | query | No | `pending` \| `approved` \| `rejected` — **defaults to `pending`**; any other value returns all |
| `page` | query | No | Page number (30 per page) |

**Response** — `200 OK` — paginated `PaymentReceiptResource` collection (`student` + `attachment` loaded).

### `GET /v1/teacher/payment-receipts/{receipt:uuid}`
**Purpose:** One receipt with its student, proof attachment, and reviewer.
**Auth:** 🧑‍🏫/assistant · `permission:finance`
**Path params:** `receipt` (uuid; tenant-scoped — cross-tenant uuid `404`s).
**Response** — `200 OK` — `PaymentReceiptResource` (`student`, `attachment`, `reviewer`).

### `POST /v1/teacher/payment-receipts/{receipt:uuid}/approve`
**Purpose:** Approve a **pending** receipt and credit the student's wallet (VD R10).
The credit posts a balanced ledger pair (`student_wallet` credit + `gateway_clearing`
debit), idempotent on op-key **`receipt:{id}`** so the credit can never double-post.
**Auth:** 🧑‍🏫/assistant · `permission:finance`

**Request body** (`ApproveReceiptRequest`)
| Field | Rules |
|---|---|
| `corrected_amount_minor` | **nullable**, integer, min 1, max `100_000_000` (1,000,000 EGP). Omit to approve as submitted. |

**Corrected-amount behaviour (D13-7 / B26):** when present, the **corrected** value is
what gets credited **and** is stamped on `corrected_amount_minor`; the original
student-submitted `amount_minor` is left untouched as the audit baseline. Omitted →
credits `amount_minor`, `corrected_amount_minor` stays `null`.

**Response** — `200 OK` — `PaymentReceiptResource` (`status: approved`, `reviewed_at`,
`reviewer`, `ledger_entry_id` set).
**Errors:** `409` receipt already reviewed (not `pending`); `422` corrected amount out of range; `404` cross-tenant.

### `POST /v1/teacher/payment-receipts/{receipt:uuid}/reject`
**Purpose:** Reject a **pending** receipt with a reason. **No ledger effect.**
**Auth:** 🧑‍🏫/assistant · `permission:finance`

**Request body** (`RejectReceiptRequest`)
| Field | Rules |
|---|---|
| `reason` | required, string, max 255 |

**Response** — `200 OK` — `PaymentReceiptResource` (`status: rejected`, `reject_reason`, `reviewed_at`).
**Errors:** `409` already reviewed; `422` missing reason; `404` cross-tenant.

### `PaymentReceiptResource`
Always: `uuid`, `method`, `amount_minor`, `corrected_amount_minor`, `currency`,
`status`, `reject_reason`, `reviewed_at`, `created_at`.
Conditional (`whenLoaded`): `student` `{ uuid, name }`, `reviewer` `{ uuid, name }`,
`attachment` (full `AttachmentResource`).
