# Wallet Module

The Wallet module (`app/Modules/Wallet`) owns each student's **wallet** and its
**append-only double-entry ledger** within a tenant. It exposes read-only
endpoints for the current student to view their balance and transaction history.

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
Wallet module itself is read-only over the API surface documented below.

## Models

| Model | Purpose |
|---|---|
| `Wallet` | A student's wallet in one tenant (`user_id`, `currency`, default `EGP`). Balance is derived, not stored. Has many `entries`. |
| `LedgerEntry` | An append-only double-entry row (`wallet_id`, `account`, `direction`, `amount_minor`, `ref_type`, `ref_id`, `idempotency_key`, `created_at`). No `updated_at`. |

Service: `LedgerService` — the single place money is written; provides
`walletFor()`, `balance()`, `alreadyPosted()`, and `post()`.

**Accounts** (`LedgerEntry` consts): `student_wallet` · `teacher_earnings` ·
`platform_commission` · `gateway_clearing`
**Directions:** `debit` · `credit`

## Endpoints

Both endpoints run inside the `tenant` middleware group and require an
authenticated, active member. They operate on the **current** student's wallet in
the **current** tenant. There is no request body.

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
