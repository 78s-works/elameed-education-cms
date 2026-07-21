# Commerce Module

The Commerce module (`app/Modules/Commerce`) owns the **checkout pipeline**, the
**Paymob payment gateway** adapter, and the **payment webhook** that confirms
gateway payments. It turns a cart into a priced order, collects payment (from the
student wallet or a card via Paymob), and on success fulfils the order —
enrolling the student, posting balanced ledger entries, and issuing an invoice.

## Checkout pipeline

```
quote  →  order  →  pay  ──(wallet)──▶ fulfil immediately
                      │
                      └──(paymob)──▶ pending + redirect_url ──▶ Paymob hosted page
                                                                      │
                                          POST /webhooks/paymob ◀─────┘
                                          verify HMAC → dedupe → fulfil
```

- **quote** — prices a cart server-side (client prices are never trusted) and
  returns line items + total. No persistence.
- **order** — re-prices and persists a `pending` Order + OrderItems.
- **pay** — `method: wallet` debits the wallet and fulfils inline;
  `method: paymob` opens a hosted charge and returns a `redirect_url`, leaving the
  order `pending` until the webhook lands.
- **webhook** — Paymob calls back; the handler verifies the signature, dedupes on
  the gateway transaction id, and fulfils the order in a DB transaction.

**Fulfilment** (`FulfillOrderService`) is idempotent and shared by both the wallet
path and the (possibly replayed) webhook: it posts a balanced ledger operation
(`order:{id}:fulfill`), grants access (course enrollments for `course` items; a
per-item enrollment for every course/unit inside a purchased `bundle` — see
[Packages](#packages-bundles) below), flips the order to `paid`, and issues a
gap-free invoice. Content revenue (courses **and** packages) is split into
`teacher_earnings` and `platform_commission` (commission % from
`config('commerce.commission_percent')`, 0 by default).

### Packages (bundles)

A **package** (`Bundle`, authored under `/teacher/bundles` — see
[Catalog › Packages](catalog.md)) groups whole courses, units, and/or individual
lessons into one sellable product. Buying a package (`item.type = bundle`) grants,
in a single transaction, an enrollment for **each** item it contains:

- a **course** item → a whole-course enrollment (unlocks its units, lessons, and
  exams — exam access is course-enrollment-based);
- a **unit** item → a unit-level enrollment (unlocks that chapter's lessons);
- a **lesson** item → a lesson-level enrollment (unlocks just that one lesson).

Exams stay tied to a full-course enrollment, so unit/lesson grants don't open them.
Every grant records the originating `bundle_id` and uses the package's own
`access_days` as its access window (null = lifetime). Granting is idempotent, so a
replayed webhook or repeat purchase never stacks duplicate enrollments.

> **Paymob is a P1 stub.** The merchant account is not live yet, so
> `PaymobGateway::createCharge()` returns a placeholder hosted-payment URL
> (`/pay/paymob/{uuid}`) and the webhook is verified with a shared HMAC secret.
> The `PaymentGateway` contract and the idempotent webhook handling are real; only
> the gateway internals get swapped when the account is approved.

## Models

| Model | Purpose |
|---|---|
| `Order` | A checkout order (`uuid`, `total_minor`, `currency`, `status`). Tenant-scoped, UUID route key. Has many `items`/`payments`, one `invoice`. |
| `OrderItem` | A priced cart line (`item_type`, `item_id`, `price_minor`, `title`). Types: `course`, `bundle` (package), `wallet_topup`, `book` (`book` unused in P1). |
| `Payment` | A payment attempt against an order (`gateway`, `gateway_txn_id`, `amount_minor`, `status`, `reference_number`, `raw_payload`, `processed_at`). |
| `Enrollment` | Grants a student access to a **course** (`course_id`), a **unit** (`unit_id`), or a single **lesson** (`lesson_id`, the last two from a package) — single source of truth for access (`bundle_id`, `source`, `starts_at`, `expires_at`, `status`). |
| `Invoice` | Internal invoice with a gap-free sequential `number` per tenant (`pdf_url`, `eta_receipt_uuid`, `issued_at`). |

Supporting services: `CheckoutService` (pricing + order creation),
`FulfillOrderService` (idempotent fulfilment), `EnrollmentService`,
`InvoiceService`. Gateway: `PaymobGateway` implements the
`Contracts\PaymentGateway` interface.

## Order / payment states

- **`OrderStatus`** (`Order.status`): `pending` · `paid` · `failed` · `refunded`
- **`Payment` status** (string consts): `pending` · `paid` · `failed`
- **`EnrollmentStatus`**: `active` · `expired` · `cancelled`
- **`EnrollmentSource`**: `purchase` · `wallet` · `code` · `manual` · `center`
  (wallet payment ⇒ `wallet`; card/webhook ⇒ `purchase`)

## Endpoints

All checkout endpoints run inside the `tenant` middleware group (tenant resolved
from the `Host` header or `X-Tenant: <slug>`) and require an authenticated,
active member. The webhook runs **outside** the tenant group.

> **Note on idempotency.** Although money endpoints are described as accepting an
> `Idempotency-Key` header, the P1 Commerce controllers do **not** read such a
> header. Idempotency is enforced server-side instead: `pay` short-circuits if the
> order is already `paid`; the webhook dedupes on `gateway_txn_id`; fulfilment
> dedupes on the ledger operation key (`order:{id}:fulfill`) and one-invoice-per-order.
> Sending `Idempotency-Key` is harmless but currently ignored here.

### Checkout

#### `POST /v1/checkout/quote`
**Purpose:** Price a cart server-side (courses + packages + wallet top-ups) and
return line items and total. Nothing is persisted.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | Yes (or `X-Tenant`) | `academy.elameed.app` |
| X-Tenant | Alt to Host | `academy` |
| Accept | Yes | `application/json` |
| Authorization | Yes | `Bearer 1|xxxx…` |
| Content-Type | Yes | `application/json` |

**Path / Query params:** None

**Request body**
```json
{
  "items": [
    { "type": "course", "course": "9f1c…-course-uuid" },
    { "type": "bundle", "bundle": "7a2d…-bundle-uuid" },
    { "type": "wallet_topup", "amount_minor": 5000 }
  ]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `items` | array | Yes | 1–50 items |
| `items[].type` | string | Yes | `course`, `bundle`, or `wallet_topup` |
| `items[].course` | string | If `type=course` | Course UUID |
| `items[].bundle` | string | If `type=bundle` | Package (bundle) UUID; must be `purchase_enabled` |
| `items[].amount_minor` | integer | If `type=wallet_topup` | Min 1; must also meet `min_topup_minor` (default 1000) |

**Response** — `200 OK`
```json
{
  "data": {
    "total_minor": 20000,
    "currency": "EGP",
    "lines": [
      { "type": "course", "title": "Grade 10 Physics", "price_minor": 15000 },
      { "type": "bundle", "title": "Term 1 Package", "price_minor": 20000 },
      { "type": "wallet_topup", "title": "Wallet top-up", "price_minor": 5000 }
    ]
  }
}
```

**Errors:** `422` — unsupported item type, empty cart, course/package not
available for purchase, or top-up below minimum (`items` validation message).

#### `POST /v1/checkout/order`
**Purpose:** Re-price the cart and persist a `pending` order with its items.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | Yes (or `X-Tenant`) | `academy.elameed.app` |
| Accept | Yes | `application/json` |
| Authorization | Yes | `Bearer 1|xxxx…` |
| Content-Type | Yes | `application/json` |
| Idempotency-Key | Optional | `a1b2c3…` (accepted but not read in P1) |

**Path / Query params:** None

**Request body** — same shape as `/checkout/quote`.
```json
{
  "items": [
    { "type": "course", "course": "9f1c…-course-uuid" }
  ]
}
```

**Response** — `201 Created` (`OrderResource`)
```json
{
  "data": {
    "uuid": "3d2b…-order-uuid",
    "status": "pending",
    "total_minor": 15000,
    "currency": "EGP",
    "items": [
      { "type": "course", "title": "Grade 10 Physics", "price_minor": 15000 }
    ]
  }
}
```

**Errors:** `422` — same pricing validation errors as quote.

#### `POST /v1/checkout/pay`
**Purpose:** Pay an existing order from the wallet (fulfils immediately) or via
Paymob (returns a hosted-payment `redirect_url`; order stays `pending` until the
webhook confirms).
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`, `throttle:auth` (10/min per IP)

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | Yes (or `X-Tenant`) | `academy.elameed.app` |
| Accept | Yes | `application/json` |
| Authorization | Yes | `Bearer 1|xxxx…` |
| Content-Type | Yes | `application/json` |
| Idempotency-Key | Optional | `a1b2c3…` (accepted but not read in P1) |

**Path / Query params:** None

**Request body**
```json
{
  "order": "3d2b…-order-uuid",
  "method": "wallet"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `order` | string | Yes | Order UUID; must belong to the caller |
| `method` | string | Yes | `wallet` or `paymob` |

**Response** — `200 OK`

Wallet payment (or an already-paid order):
```json
{ "data": { "status": "paid", "order": "3d2b…-order-uuid" } }
```

Paymob payment (hosted redirect; order remains pending):
```json
{
  "data": {
    "status": "pending",
    "order": "3d2b…-order-uuid",
    "redirect_url": "https://academy.elameed.app/pay/paymob/3d2b…-order-uuid"
  }
}
```

**Errors:**
- `422` — `order`: "Order not found." (unknown UUID or not owned by caller)
- `422` — `wallet`: "Insufficient wallet balance." (wallet balance < order total)

### Webhooks

#### `POST /v1/webhooks/paymob`
**Purpose:** Paymob payment callback. Verifies the signature, dedupes on the
gateway transaction id, and fulfils the referenced order (enroll + ledger post +
invoice). Runs on the platform host — the tenant is derived from the order, not
the `Host` header.
**Auth:** 🔐 Gateway signature (HMAC-SHA512). No Sanctum, no tenant middleware.
**Middleware:** `throttle:120,1` (120/min). **Outside** the `tenant` group.

**Request headers**
| Header | Required | Example |
|---|---|---|
| Content-Type | Yes | `application/json` |
| X-Paymob-Hmac | Yes* | `<hex sha512 signature>` |

\* The signature may instead be supplied as an `hmac` field in the body; the
header `X-Paymob-Hmac` takes precedence. It is validated (`hash_equals`) against
`hash_hmac('sha512', signingString, config('commerce.paymob.hmac_secret'))` where
the signing string is `transaction_id|order_uuid|amount_cents|<true|false>`.

**Path / Query params:** None

**Request body** — fields the P1 stub parser reads (flat, top-level):
```json
{
  "transaction_id": "pmb_txn_123456789",
  "order_uuid": "3d2b…-order-uuid",
  "amount_cents": 15000,
  "success": true,
  "hmac": "computed-hmac-signature"
}
```

| Field | Type | Notes |
|---|---|---|
| `transaction_id` | string | Stored as `Payment.gateway_txn_id`; dedupe key |
| `order_uuid` | string | Resolves the order (and its tenant) |
| `amount_cents` | integer | Minor units; falls back to order total if 0 |
| `success` | boolean | `true` ⇒ `paid`, otherwise `failed` |
| `hmac` | string | Signature (alternative to `X-Paymob-Hmac` header) |

> The Postman sample uses Paymob's real **nested** shape
> (`{ "type": "TRANSACTION", "obj": { "id", "success", "amount_cents", "order": { "id" }, "hmac" } }`).
> The current stub parser reads the **flat** fields above; the nested→flat mapping
> is part of the "swap when live" work.

**Response** — `200 OK` acknowledgements:
```json
{ "data": { "status": "paid", "order": "3d2b…-order-uuid" } }
```
```json
{ "data": { "status": "failed" } }
```
```json
{ "data": { "status": "already_processed" } }
```

**Errors:**
- `400` — `invalid_signature`: "Bad signature." (HMAC mismatch)
- `404` — `order_not_found`: "Unknown order." (no order matches `order_uuid`)

An already-`paid` transaction (matched by `gateway_txn_id`) returns
`200 { "data": { "status": "already_processed" } }` — the idempotent replay path.
