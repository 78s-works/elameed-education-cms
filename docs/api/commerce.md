# Commerce Module

The Commerce module (`app/Modules/Commerce`) owns the **checkout pipeline**, the
**Paymob payment gateway** adapter, the **payment webhook** that confirms
gateway payments, and **coupons / promo codes** (M21). It turns a cart into a
priced order, applies an optional coupon discount, collects payment (from the
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
(`order:{id}:fulfill`), grants access (a course enrollment for a `course` item, a
lesson enrollment for a `lesson` item, and a per-lesson fan-out for every lesson
inside a purchased `package` — see [Packages & lessons](#packages--lessons) below),
flips the order to `paid`, issues a
gap-free invoice, and renders that invoice to a PDF on a private disk (best-effort,
outside the money transaction — see [Invoices](#invoices)). Content revenue (courses **and** packages,
**net of any coupon discount**) is split into `teacher_earnings` and
`platform_commission` (commission % from `config('commerce.commission_percent')`,
0 by default) — so the teacher absorbs the coupon reduction. When the order carries
a `coupon_id`, its `used_count` is incremented once inside this transaction.

### Packages & lessons

Since the VD change set (B15/LP-D2), the sellable content units are the **recursive
content package** (`Package`, authored under `/teacher/content-packages` — see
[Catalog](catalog.md)) and the **standalone lesson**. Units and the old `Bundle`
were retired.

- Buying a **`lesson`** (`item.type = lesson`) grants a single lesson enrollment.
- Buying a **`package`** (`item.type = package`) **fans out** into one lesson
  enrollment for **each lesson the package contains**, recursing through
  sub-packages, in a single transaction. Every grant records the originating
  **`package_id`** (buy provenance).
- Buying a **`course`** (`item.type = course`) still grants a whole-course
  enrollment (unlocks its lessons + exams — exam access is course-enrollment-based).

Granting is **idempotent** (unique on `(user, lesson/course)`), so a replayed webhook
or repeat purchase never stacks duplicate enrollments.

> **Paymob is a P1 stub.** The merchant account is not live yet, so
> `PaymobGateway::createCharge()` returns a placeholder hosted-payment URL
> (`/pay/paymob/{uuid}`) and the webhook is verified with a shared HMAC secret.
> The `PaymentGateway` contract and the idempotent webhook handling are real; only
> the gateway internals get swapped when the account is approved.

## Models

| Model | Purpose |
|---|---|
| `Order` | A checkout order (`uuid`, `subtotal_minor`, `discount_minor`, `total_minor`, `currency`, `status`, `coupon_id`). Tenant-scoped, UUID route key. Has many `items`/`payments`, one `invoice`, one `coupon`. |
| `OrderItem` | A priced cart line (`item_type`, `item_id`, `price_minor`, `title`). Types: `course`, `package` (recursive content package), `lesson`, `wallet_topup` (`bundle` retired). |
| `Coupon` | A discount code (M21): `uuid`, `code` (unique per tenant), `type` (`percent`/`fixed`), `value`, `course_id` (nullable = cart-wide), `min_subtotal_minor`, `usage_limit`, `used_count`, `starts_at`/`expires_at`, `is_active`. Tenant-scoped, soft-deletes, UUID route key. `isRedeemable()` + `discountFor()`. |
| `Payment` | A payment attempt against an order (`gateway`, `gateway_txn_id`, `amount_minor`, `status`, `reference_number`, `raw_payload`, `processed_at`). |
| `Enrollment` | Grants a student access to a **course** (`course_id`) or a single **lesson** (`lesson_id`) — the single source of truth for access (`package_id` = originating package when the grant came from a package buy, `source`, `starts_at`, `expires_at`, `status`). `unit_id` is a dormant column (units retired). |
| `Invoice` | Internal invoice with a gap-free sequential `number` per tenant (`uuid` route key, `pdf_url` = private-disk path to the rendered PDF, `eta_receipt_uuid`, `issued_at`). |

Supporting services: `CheckoutService` (pricing + order creation),
`CouponService` (validates a code against a priced cart + computes the discount),
`FulfillOrderService` (idempotent fulfilment), `EnrollmentService`,
`InvoiceService` (gap-free numbering), `InvoicePdfService` (renders the
`invoices.pdf` Blade view to a PDF via `barryvdh/laravel-dompdf`, stores it on the
private `invoices.disk`, and populates `pdf_url`; regenerates on demand if missing).
Gateway: `PaymobGateway` implements the `Contracts\PaymentGateway` interface.

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
    { "type": "package", "package": "7a2d…-package-uuid" },
    { "type": "lesson", "lesson": 101 },
    { "type": "wallet_topup", "amount_minor": 5000 }
  ],
  "coupon": "SUMMER25"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `items` | array | Yes | 1–50 items |
| `items[].type` | string | Yes | `course`, `package`, `lesson`, or `wallet_topup` |
| `items[].course` | string | If `type=course` | Course UUID |
| `items[].package` | string | If `type=package` | Content-package UUID; must be `is_purchasable` |
| `items[].lesson` | integer | If `type=lesson` | Lesson id; must be `is_purchasable` |
| `items[].amount_minor` | integer | If `type=wallet_topup` | Min 1; must also meet `min_topup_minor` (default 1000) |
| `coupon` | string | No | Optional promo code (M21), max 64. Validated + priced server-side; only discounts **content** lines (courses/lessons/packages) — never wallet top-ups. |

**Response** — `200 OK`
```json
{
  "data": {
    "subtotal_minor": 40000,
    "discount_minor": 10000,
    "total_minor": 30000,
    "currency": "EGP",
    "coupon": "SUMMER25",
    "lines": [
      { "type": "course", "title": "Grade 10 Physics", "price_minor": 15000 },
      { "type": "package", "title": "Term 1 Package", "price_minor": 20000 },
      { "type": "lesson", "title": "Displacement & Velocity", "price_minor": 5000 },
      { "type": "wallet_topup", "title": "Wallet top-up", "price_minor": 5000 }
    ]
  }
}
```

`subtotal_minor` is the pre-discount sum of all lines; `discount_minor` is the
coupon reduction (0 with no coupon); `total_minor` = `subtotal − discount` (floored
at 0); `coupon` is the accepted code, or `null` when none was supplied.

**Errors:** `422` — unsupported item type, empty cart, course/package not
available for purchase, top-up below minimum (`items` validation message), or an
invalid/expired/used-up/not-applicable `coupon` (`coupon` validation message).

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

**Request body** — same shape as `/checkout/quote` (incl. the optional `coupon`).
```json
{
  "items": [
    { "type": "course", "course": "9f1c…-course-uuid" }
  ],
  "coupon": "SUMMER25"
}
```

**Response** — `201 Created` (`OrderResource`)
```json
{
  "data": {
    "uuid": "3d2b…-order-uuid",
    "status": "pending",
    "subtotal_minor": 15000,
    "discount_minor": 3750,
    "total_minor": 11250,
    "currency": "EGP",
    "coupon": "SUMMER25",
    "items": [
      { "type": "course", "title": "Grade 10 Physics", "price_minor": 15000 }
    ]
  }
}
```

`OrderResource` now exposes `subtotal_minor`, `discount_minor`, and `coupon` (the
code) alongside `total_minor`. `total_minor` is the payable amount (already net of
the discount). The coupon's `used_count` is **not** incremented here — only at
fulfilment.

**Errors:** `422` — same pricing/coupon validation errors as quote.

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

### Coupons (M21)

Discount codes the teacher issues and the student applies at checkout. A coupon is
`percent` (value = 1–100) or `fixed` (value = minor units), optionally scoped to a
single `course` (else it discounts the whole **content** subtotal). Coupons never
discount wallet top-ups. The teacher **absorbs** the discount at fulfilment (it
reduces `teacher_earnings`, so the ledger stays balanced), and `used_count`
increments **once** at fulfilment (a replayed webhook can't double-count).

#### `POST /v1/coupons/validate`
**Purpose:** Preview a coupon against a cart without creating an order. Returns the
computed discount when the code is valid.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request body** — same shape as `/checkout/quote` (`items` + `coupon`).

**Response** — `200 OK`
```json
{
  "data": {
    "valid": true,
    "coupon": "SUMMER25",
    "discount_minor": 10000,
    "total_minor": 30000
  }
}
```

`valid` is `false` only when **no** `coupon` was supplied (an empty cart-price
preview). A supplied-but-invalid/expired/used-up/not-applicable code raises the
`422` below instead of returning `valid: false`.

**Errors:** `422` — `coupon` (`This coupon is not valid.` / `…does not apply to
the items in your cart.` / `Your cart has not reached this coupon's minimum.`), or
`items` pricing errors as in quote.

---

The five endpoints below manage the teacher's own coupons. All run inside the
`tenant` group and require an active **teacher**. Coupons bind by `uuid` and are
tenant-scoped (`BelongsToTenant`), so a valid uuid from another tenant resolves to
`404`. Common middleware: `tenant`, `auth:sanctum`, `active`, `role:teacher`.

The `CouponResource` shape (returned by every non-delete endpoint):
```json
{
  "uuid": "8c1a…-coupon-uuid",
  "code": "SUMMER25",
  "type": "percent",
  "value": 25,
  "course": "9f1c…-course-uuid",
  "min_subtotal_minor": null,
  "usage_limit": 100,
  "used_count": 12,
  "starts_at": null,
  "expires_at": "2026-09-01T00:00:00+00:00",
  "is_active": true,
  "redeemable": true,
  "created_at": "2026-07-27T10:00:00+00:00"
}
```
`course` is the scoped course **uuid** (or `null` = cart-wide); `redeemable`
reflects `isRedeemable()` (active, within the date window, under `usage_limit`).

#### `GET /v1/teacher/coupons`
**Purpose:** List the academy's coupons, newest first, paginated 20/page.
**Response** — `200 OK` — `CouponResource` collection + `meta`/`links`.

#### `POST /v1/teacher/coupons`
**Purpose:** Create a coupon.

**Request body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `code` | string | Yes | max 64; unique per tenant |
| `type` | string | Yes | `percent` \| `fixed` |
| `value` | integer | Yes | ≥ 1; a `percent` value may not exceed 100 |
| `course` | string | No | nullable; a course **uuid** in this tenant (null = cart-wide) |
| `min_subtotal_minor` | integer | No | nullable; ≥ 0 |
| `usage_limit` | integer | No | nullable; ≥ 1 (null = unlimited) |
| `starts_at` | date | No | nullable |
| `expires_at` | date | No | nullable; `after_or_equal:starts_at` |
| `is_active` | boolean | No | defaults `true` |

**Response** — `201 Created` — the created `CouponResource`.
**Errors:** `422` — validation (duplicate `code`, percent > 100, unknown `course`, bad dates).

#### `GET /v1/teacher/coupons/{coupon:uuid}`
**Purpose:** Show one coupon. **Response** — `200 OK` `CouponResource`. **Errors:** `404` unknown/cross-tenant.

#### `PUT /v1/teacher/coupons/{coupon:uuid}`
**Purpose:** Update a coupon. All fields `sometimes` (partial); `code` unique-ignoring-self.
**Response** — `200 OK` `CouponResource`. **Errors:** `422` validation; `404` unknown/cross-tenant.

#### `DELETE /v1/teacher/coupons/{coupon:uuid}`
**Purpose:** Retire a coupon (soft delete). **Response** — `204 No Content`. **Errors:** `404` unknown/cross-tenant.

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

### Invoices

Every paid order gets a gap-free invoice; its PDF is rendered on fulfilment and
stored on a **private** disk (`config('invoices.disk')`, default `local`). The PDF
is never served directly — it is streamed only through the access-controlled
download endpoint below. All three endpoints run inside the `tenant` group and
require an authenticated, active member; invoices bind by `uuid` and are
tenant-scoped, so a valid uuid from another tenant resolves to **404**.

**Access rule (detail + download):** the **buyer** (the order's owner), or an
active **teacher/assistant** of the invoice's tenant. Anyone else → `403`.

#### `GET /v1/invoices`
**Purpose:** List the current user's invoices in this tenant (most recent first, paginated 30/page).
**Auth:** 👤 Authenticated · **Middleware:** `tenant`, `auth:sanctum`, `active`

**Response** — `200 OK` (`InvoiceResource` collection)
```json
{
  "data": [
    {
      "uuid": "b2c1…-invoice-uuid",
      "number": 42,
      "issued_at": "2026-07-27T10:15:00+00:00",
      "pdf_available": true,
      "download_url": "https://academy.elameed.app/api/v1/invoices/b2c1…-invoice-uuid/download",
      "order": { "uuid": "3d2b…-order-uuid", "total_minor": 15000, "currency": "EGP", "items": [ … ] }
    }
  ],
  "links": { … }, "meta": { … }
}
```

#### `GET /v1/invoices/{invoice:uuid}`
**Purpose:** One invoice's detail (incl. order + line items + `download_url`).
**Auth:** 👤 Buyer or tenant teacher/assistant · **Middleware:** `tenant`, `auth:sanctum`, `active`
**Response** — `200 OK` — a single `InvoiceResource` under `data`.
**Errors:** `403` — not the buyer and not tenant staff · `404` — unknown/other-tenant uuid.

#### `GET /v1/invoices/{invoice:uuid}/download`
**Purpose:** Stream the invoice PDF (`Content-Type: application/pdf`, `attachment`).
Renders on first request if the file is missing.
**Auth:** 👤 Buyer or tenant teacher/assistant · **Middleware:** `tenant`, `auth:sanctum`, `active`
**Response** — `200 OK` — binary PDF body.
**Errors:** `403` · `404` as above.

> **Arabic note.** The `invoices.pdf` template is bilingual (EN/AR labels) and
> RTL-ready, rendered with dompdf's Unicode `DejaVu Sans`. Full Arabic **glyph
> shaping/joining** (e.g. an Arabic academy name) needs an Arabic-capable TTF
> bundled + a shaping step (dompdf has no bidi engine) — a follow-up polish item;
> the PDF renders and downloads correctly today with English content reliable.
