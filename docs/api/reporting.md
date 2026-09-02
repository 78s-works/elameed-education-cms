# Reporting Module

> The Reporting module exposes read-only summaries and the immutable activity trail that sit on top of other modules' data. It has three surfaces: a **student** "my courses" view with per-course progress, **teacher report** aggregates (sales/revenue and student/course counts for the teacher's own academy), and the **audit log** (M18) — an append-only record of privileged actions. The audit log is read at two scopes by two methods of the same controller: `teacher` (own-tenant, wired here) and `admin` (cross-tenant, documented in the Platform Admin module). All non-admin endpoints run inside the `tenant` group; the teacher report/audit endpoints additionally require `role:teacher`.

Money is reported as an integer in minor units (`*_minor`); the platform base currency is EGP. Timestamps are ISO-8601 UTC.

## Models

- **`AuditLog`** (`audit_logs`) — Append-only audit record. **Not** tenant-scoped at the model level: `tenant_id` is nullable (cross-tenant admin actions), so reads filter `tenant_id` explicitly. `UPDATED_AT` is disabled (`const UPDATED_AT = null`) — rows are write-once with only a `created_at`. Fillable: `tenant_id`, `actor_user_id`, `action`, `subject_type`, `subject_id`, `meta` (JSON, cast to array), `ip`. Relation: `actor()` -> `App\Models\User` (via `actor_user_id`).

> The student and teacher report endpoints read from **other modules'** models (`Catalog\Course`, `Commerce\Enrollment` / `Order`, `Engagement\LessonProgress`, `Wallet\LedgerEntry`, `Identity\TenantUser`); Reporting owns only `AuditLog`.

---

## Endpoints

### Student

#### `GET /v1/me/courses`

**Purpose:** Return the authenticated student's accessible courses (from access-granting enrollments) in the current tenant, each with a lesson-progress summary. Not paginated — returns a plain `data` array.

**Auth:** 👤 Authenticated student (any active member)
**Middleware:** `tenant` group -> `auth:sanctum` -> `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "uuid": "0b8f9c2e-1d34-4a76-9c0e-77e2a1b3c4d5",
      "title": "الفيزياء - الصف الثالث الثانوي",
      "slug": "physics-g3",
      "cover_url": "https://cdn.raqeem-tech.com/courses/12/cover.jpg",
      "lessons_total": 24,
      "lessons_completed": 9,
      "watch_precent": 37,
      "progress_percent": 38
    }
  ]
}
```

Notes:
- Courses are derived from `Enrollment` rows for the user that `grantsAccess()` and have a non-null `course_id`.
- `lessons_total` is the course's lesson count; `lessons_completed` counts `LessonProgress` rows with a non-null `completed_at`.
- `progress_percent` is `round(completed / total * 100)` (integer), or `0` when the course has no lessons.
- `watch_precent` is spelled with the typo as returned by the API (the JSON key is literally `watch_precent`); it reflects a single `LessonProgress.watch_percent` value and is `0` when no progress row exists.

**Errors:**
- `401 unauthenticated` — missing/invalid bearer token.
- `403` — not an active member of the resolved tenant.
- `403 / 404` — unregistered or non-active host (domain gate).

---

### Teacher · Reports

#### `GET /v1/teacher/reports/sales`

**Purpose:** Revenue snapshot for the teacher's own academy: total teacher earnings, gross paid volume, and count of paid orders. All figures tenant-scoped to the caller's academy.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None (no date-range filter in Phase 1 — the report is all-time).

**Request body:** None

**Response 200**

```json
{
  "data": {
    "earnings_minor": 4820000,
    "gross_minor": 5100000,
    "orders_paid": 128
  }
}
```

Notes:
- `earnings_minor` = sum of `LedgerEntry` credits on the `teacher_earnings` account (tenant-scoped via `BelongsToTenant`).
- `gross_minor` = sum of `total_minor` over orders with status `paid`.
- `orders_paid` = count of `paid` orders.
- Amounts are integer minor units (EGP); no `currency` field is emitted by this endpoint.

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant (`role:teacher`).
- `401 unauthenticated` — missing/invalid bearer token.

---

#### `GET /v1/teacher/reports/students`

**Purpose:** Headline counts for the teacher's academy: number of active students and number of courses.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "students": 342,
    "courses": 11
  }
}
```

Notes:
- `students` counts `TenantUser` rows for the current `tenant_id` with `role = student` and `status = active` (the `tenant_user` table is global, so it is filtered by `tenant_id` explicitly).
- `courses` is the tenant-scoped `Course` count.

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant.
- `401 unauthenticated` — missing/invalid bearer token.

---

#### `GET /v1/teacher/reports/overview`

**Purpose:** The teacher dashboard's single aggregate call — headline student / course / sales counts, a 12-month time series, top courses, and the most recent sales. All figures tenant-scoped to the caller's academy.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Path / Query params:** None. The window is fixed server-side: the `series` is the current month + the prior 11 (12 buckets); "this month" figures use the current calendar month.

**Request body:** None

**Response 200**

```json
{
  "data": {
    "students_total": 342,
    "students_active": 315,
    "students_new_month": 28,
    "courses_total": 11,
    "courses_published": 9,
    "enrollments_total": 1204,
    "sales_this_month_minor": 480000,
    "sales_total_minor": 4820000,
    "orders_paid": 128,
    "avg_order_minor": 37656,
    "series": [
      { "key": "2025-09", "label": "Sep", "revenue_minor": 210000, "enrollments": 60, "students": 22 }
    ],
    "top_courses": [
      { "title": "فيزياء الصف الثالث الثانوي", "enrollments": 210, "revenue_minor": 1050000 }
    ],
    "recent_sales": [
      { "student": "أحمد علي", "course": "فيزياء الصف الثالث الثانوي", "amount_minor": 50000, "at": "2026-08-12T18:03:22+00:00" }
    ]
  }
}
```

Notes:
- Monetary values are integer minor units (EGP). `sales_*_minor` and `series[].revenue_minor` derive from `LedgerEntry` **credits** on the `teacher_earnings` account; `orders_paid` counts `paid` orders and `avg_order_minor` is `intdiv(gross, orders_paid)` (0 when there are no paid orders).
- `series` is always 12 objects (`key` = `Y-m`, `label` = short month). `top_courses` is up to 5 (by enrollment count; missing title → `"—"`); `recent_sales` is up to 8 (last paid orders; `student` / `course` may be `null`).
- Companion endpoints `GET /teacher/reports/sales` and `.../students` above return the same figures in smaller slices.

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant.
- `401 unauthenticated` — missing/invalid bearer token.

---

### Teacher · Sales ledger (M17)

The transaction list behind the dashboard's revenue widgets. Every endpoint below
takes the SAME filter set, so the rows, the totals, the top-ups panel and the
export always describe one slice.

**Auth:** permission `finance.sales.view` (refunding needs `finance.refunds.manage`)
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `can:...`

**Shared query params**

| Param | Type | Notes |
|---|---|---|
| `date_from` / `date_to` | date | Inclusive DAY bounds (`from` -> 00:00, `to` -> 23:59:59). |
| `item_type` + `item_id` | `lesson`\|`package`\|`book` + int | One sellable item. (`courses` are retired — VD §7.) |
| `student_id` | string | The student's **uuid** (a numeric id is also accepted). Unknown uuid → zero rows. |
| `method[]` | enum | `fawry`, `card_paymob`, `wallet`, `code`, `manual`, `center`, `free`. |
| `status[]` | enum | `paid`, `pending`, `failed`, `refunded`. |
| `q` | string | Student name / phone, or order reference. |
| `page`, `per_page` | int | `per_page` max 200, default 25. |

#### The unified payment method

"Payment method" is not a stored column; it is derived per row:

| Value | Derived from |
|---|---|
| `card_paymob` | latest `payments.gateway = paymob` on the order (any attempt state). |
| `fawry` | latest `payments.gateway = fawry`. Reserved — works the day the gateway goes live. |
| `wallet` | a paid order with no gateway payment row (funded from wallet balance). |
| `code` | `enrollments.source = code` — a content activation code redeemed (M12). |
| `manual` | `enrollments.source = manual` — a staff-issued grant. |
| `center` | `enrollments.source = center` — enrolled by center attendance. |
| `free` | the row's net amount is 0. |

> A manual receipt (Vodafone Cash / InstaPay) tops up the **wallet**; it never buys
> content directly. A purchase funded that way therefore reports as `wallet`, and
> the receipt itself appears under wallet top-ups.

#### `GET /v1/teacher/sales`

**Purpose:** Paginated rows, newest first, plus totals for the active filter.

A row is one transaction — a checkout order, or a grant that never had one
(code / staff / center; those carry no `orders` row at all, so the row set is a
UNION of both). `items[]` is the row's sub-lines. A package grant fans out into
one enrollment per descendant lesson; those are folded back into ONE row per
(student, package, source), priced at the package's current price.

**Response 200**

```json
{
  "data": [
    {
      "kind": "order",
      "id": 5512,
      "reference": "01a0…-order-uuid",
      "occurred_at": "2026-08-24 11:04:12",
      "status": "paid",
      "method": "card_paymob",
      "student": { "uuid": "01a0…", "name": "أحمد علي", "phone": "01011112222" },
      "items": [{ "item_type": "lesson", "item_id": 88, "title": "الجبر", "price_minor": 20000 }],
      "currency": "EGP",
      "gross_minor": 20000,
      "discount_minor": 5000,
      "net_minor": 15000,
      "refunded_minor": 0,
      "coupon_code": "BACK2SCHOOL",
      "refundable_minor": 15000,
      "refunds": []
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 348, "last_page": 14 },
  "totals": {
    "currency": "EGP",
    "transactions": 348,
    "collected_minor": 5100000,
    "refunded_minor": 60000,
    "net_sales_minor": 5040000,
    "pending_minor": 40000, "pending_count": 3,
    "failed_minor": 20000, "failed_count": 2,
    "refunded_count": 4,
    "by_method": [{ "method": "wallet", "net_minor": 2200000, "transactions": 140 }]
  }
}
```

Notes:
- **Totals follow the filter, never the whole dataset.**
- `net_sales_minor` = `collected_minor` (paid + refunded rows — a refunded row WAS
  collected) − `refunded_minor` (`SUM(refunds.amount_minor)`, so a partial refund
  subtracts only its own amount). Pending / failed appear in the table and in their
  own totals, never in this figure.
- Wallet top-up orders produce **no row** and no revenue here.
- `reference` is the order uuid for a checkout, the redeemed code for a code grant,
  else `GRANT-{id}`.
- All amounts are integer minor units.

#### `GET /v1/teacher/sales/filters`

**Purpose:** Dropdown vocabularies — the academy's sellable lessons + packages
(`{item_type, item_id, title, price_minor}`), the 7 method values with labels, and
the 4 status values.

#### `GET /v1/teacher/sales/topups`

**Purpose:** Wallet top-ups in the same date window, reported **apart** from sales:
a top-up is counted when the student SPENDS it, so adding it to revenue would
count the same money twice. Sources: `gateway` (top-up checkout), `code` (wallet
activation code), `manual_receipt` (approved Vodafone Cash / InstaPay receipt,
using the reviewer's corrected amount when there is one). The response carries
`counted_in_sales: false` to say so explicitly.

#### `GET /v1/teacher/sales/export`

**Purpose:** The current filter as a spreadsheet, same columns as the table
(`format=csv|xlsx`, streamed via openspout). One line per ITEM; a
transaction-level discount / refund is attributed to the first line. **Amounts are
in pounds here** — the only place minor units are converted, because a human reads
the file.

### Teacher · Refunds (M17)

#### `POST /v1/teacher/orders/{order:uuid}/refunds`

**Purpose:** Refund a paid order. **Auth:** permission `finance.refunds.manage`.

| Param | Type | Notes |
|---|---|---|
| `amount_minor` | int, optional | Omitted → refund everything still refundable (the whole order, first time). |
| `reason` | string, optional | Recorded on the refund row and in the audit log. |
| `destination` | `wallet`\|`offline` | Default `wallet`. |

One call, one transaction: a balanced ledger reversal (`ref_type = refund`,
debiting `teacher_earnings` + the platform's commission share), a `refunds` row,
and — for a FULL refund — the order flips to `refunded` and the enrollments it
granted are `cancelled`. A partial refund is a price correction: access survives
and the order stays `paid`.

Money goes back to the student's **wallet** (gateway refund APIs are not wired:
Paymob is stubbed, Fawry not live). `destination = offline` records a refund
settled in cash outside the platform — the books move, the wallet does not.

**Response 201** — `{ "data": { "uuid", "order_uuid", "amount_minor", "currency", "destination", "reason", "revoked_access", "order_status", "refundable_minor" } }`

**Errors:** `422` — order not paid, already fully refunded, or the amount exceeds
the refundable remainder · `403` — missing `finance.refunds.manage` · `404` — unknown/other-tenant order.

#### `GET /v1/teacher/orders/{order:uuid}/refunds`

**Purpose:** The refunds already posted against one order (rides on
`finance.sales.view`). `meta` carries `order_total_minor`, `refunded_minor`,
`refundable_minor`.

---

### Teacher · Audit log

#### `GET /v1/teacher/audit-logs`

**Purpose:** Read the teacher's own-academy audit trail (M18) — an append-only list of privileged actions, newest first. Scope is hard-pinned to the caller's tenant; a teacher can never see another academy's entries.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.edu.raqeem-tech.com` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `page` | query | no | Page number (default 1). Page size fixed at 50. |

> Note: the `?tenant=` filter accepted by the admin variant is **ignored here** — the teacher scope is forced to the resolved tenant.

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "action": "student.updated",
      "actor": "مستر خالد",
      "subject_type": "student",
      "subject_id": 8817,
      "meta": {
        "changes": { "status": "active" }
      },
      "ip": "197.45.12.9",
      "created_at": "2026-07-15T08:22:41+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 6,
    "total": 273
  }
}
```

Notes:
- `actor` is the actor user's `name` (string) or `null` when unavailable — it is not a nested object.
- per-row `meta` is the action's arbitrary JSON context; `ip` is the source IP recorded at write time.
- The list `meta` block here is the controller's **custom** paginator shape — only `current_page`, `last_page`, `total` (no `per_page` / `from` / `to`).

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant.
- `401 unauthenticated` — missing/invalid bearer token.
