# Billing Module (Teacher Subscription Packages)

> The Billing module (M03, `FR-M03-01..04`) is the platform's teacher-monetisation surface: the platform admin defines **subscription packages** (plans with a price, billing interval, free trial, and usage limits) and **assigns** them to teacher academies (tenants). Each teacher can view their own current plan, its limits, and usage.

**Two audiences, two surfaces:**
- **Admin** (`/v1/admin/packages`, `/v1/admin/tenants/{tenant:uuid}/subscription`) — cross-tenant, **host-pinned to the admin console** and **not** tenant-scoped, exactly like the rest of `/admin/*`. Middleware `central` → `auth:sanctum` → `admin`. Off the admin host → `404`; non-admin → `403`.
- **Teacher** (`GET /v1/teacher/subscription`) — tenant-scoped, read-only. Middleware `tenant` group → `auth:sanctum` → `active` → `role:teacher`.

**Global tables.** `subscription_packages` and `tenant_subscriptions` are **global** (not tenant-scoped, no RLS / `BelongsToTenant`) — plans are platform-wide and a subscription is read by the owning teacher via an explicit `tenant_id` filter, the same pattern as `tenant_user`. `tenants.package_id` is the denormalised pointer to the current plan.

Money is integer minor units (`price_minor`), base currency EGP. Timestamps are ISO-8601 UTC. Packages/tenants bind by `uuid` on the path, never internal id.

## Models & artifacts

- **`SubscriptionPackage`** (`app/Modules/Billing/Models`) — global, soft-deletes. `interval` cast to `BillingInterval` (`monthly`/`yearly`); `limits` cast to array. `LIMIT_KEYS = [max_students, max_courses, storage_mb, max_assistants]`; a `null` limit value = **unlimited**.
- **`TenantSubscription`** — global (`tenant_id` present but not scoped). `status` cast to `SubscriptionStatus` (`trialing`/`active`/`past_due`/`canceled`/`expired`). `price_minor` is locked at assignment (may differ from the plan price for a discount).
- **Services:** `SubscriptionService` (assign/supersede + `current(tenantId)`), `PackageUsage` (usage-vs-limits snapshot). Enums `SubscriptionStatus`, `BillingInterval`.
- **Resources:** `PackageResource`, `TenantSubscriptionResource`.

> **Not yet enforced:** the limits are **reported** (admin sets them, the teacher view shows usage vs limit) but creation paths (course/student/assistant/storage) do **not** yet block on them — that enforcement is a tracked follow-up. `storage_mb.used` is always `0` until media-tier byte counting lands.

---

## Admin — packages

### `GET /v1/admin/packages`

**Purpose:** List all packages (including inactive; **excludes** retired/soft-deleted), ordered by `sort_order` then `id`. **Not paginated** — returns a plain `data` array.

**Auth:** 🛡️ Platform admin · **Middleware:** `central` → `auth:sanctum` → `admin`

**Response 200**

```json
{
  "data": [
    {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "slug": "growth",
      "name": "Growth",
      "description": "For a growing academy with multiple courses.",
      "price_minor": 150000,
      "currency": "EGP",
      "interval": "monthly",
      "trial_days": 14,
      "limits": { "max_students": 2000, "max_courses": 30, "storage_mb": 50000, "max_assistants": 3 },
      "is_active": true,
      "sort_order": 2,
      "created_at": "2026-07-16T10:00:00+00:00"
    }
  ]
}
```

**Errors:** `404` (off admin host) · `403` (not admin) · `401`.

---

### `POST /v1/admin/packages`

**Purpose:** Create a package.

**Auth:** 🛡️ Platform admin · **Middleware:** `central` → `auth:sanctum` → `admin`

**Request body**

```json
{
  "name": "Growth",
  "slug": "growth",
  "description": "For a growing academy.",
  "price_minor": 150000,
  "currency": "EGP",
  "interval": "monthly",
  "trial_days": 14,
  "is_active": true,
  "sort_order": 2,
  "limits": { "max_students": 2000, "max_courses": 30 }
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `slug` | string | yes | max 100, regex `^[a-z0-9-]+$`, unique in `subscription_packages.slug` |
| `description` | string | no | max 2000 |
| `price_minor` | integer | yes | ≥ 0 (minor units) |
| `currency` | string | no | 3 chars (default `EGP`) |
| `interval` | string | no | `monthly` \| `yearly` (default `monthly`) |
| `trial_days` | integer | no | 0–365 (default 0) |
| `is_active` | boolean | no | default `true` |
| `sort_order` | integer | no | ≥ 0 |
| `limits` | object | no | keys below; only canonical keys are persisted |
| `limits.max_students` / `max_courses` / `storage_mb` / `max_assistants` | integer\|null | no | ≥ 0; **omit or `null` = unlimited** |

**Response 201** — a single `PackageResource` (same shape as the list rows). `limits` in the response **always** contains all four canonical keys; unset ones are `null`.

**Errors:** `422` (validation) · `404` (off host) · `403` · `401`.

---

### `GET /v1/admin/packages/{package:uuid}`
Fetch one package by uuid. `404` if not found or retired. → single `PackageResource`.

### `PUT /v1/admin/packages/{package:uuid}`

**Purpose:** Update a package. All fields `sometimes` (partial update); `slug` unique-ignoring-self. Sending `limits` replaces the stored limits (only canonical keys kept). → single `PackageResource`.

**Errors:** `422` · `404` · `403` · `401`.

### `DELETE /v1/admin/packages/{package:uuid}`

**Purpose:** **Retire** a package (soft delete). It disappears from the catalogue but existing `tenant_subscriptions` keep their `package_id`. **Response `204`** (no body).

**Errors:** `404` · `403` · `401`.

---

## Admin — tenant subscription

### `GET /v1/admin/tenants/{tenant:uuid}/subscription`

**Purpose:** The tenant's current (trialing/active/past_due) subscription, or `null`.

**Auth:** 🛡️ Platform admin · **Middleware:** `central` → `auth:sanctum` → `admin`

**Response 200**

```json
{
  "data": {
    "uuid": "1f7c...",
    "status": "trialing",
    "price_minor": 150000,
    "currency": "EGP",
    "started_at": "2026-07-16T10:00:00+00:00",
    "trial_ends_at": "2026-07-30T10:00:00+00:00",
    "renews_at": "2026-08-30T10:00:00+00:00",
    "ends_at": null,
    "package": { "uuid": "…", "slug": "growth", "…": "…full PackageResource…" }
  }
}
```

`data` is `null` when the tenant has no current subscription.

**Errors:** `404` (unknown tenant / off host) · `403` · `401`.

---

### `POST /v1/admin/tenants/{tenant:uuid}/subscription`

**Purpose:** Assign / upgrade / downgrade the tenant to a package (FR-M03-03). Supersedes any current subscription (marks it `canceled`), opens a fresh one, and syncs `tenants.package_id` + `tenants.trial_ends_at`. Supports a new-teacher discount via `price_minor`/`trial_days` overrides (FR-M03-04). Audit-logged as `tenant.subscription.assigned`.

**Auth:** 🛡️ Platform admin · **Middleware:** `central` → `auth:sanctum` → `admin`

**Request body**

```json
{
  "package_uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
  "price_minor": 99000,
  "trial_days": 30,
  "discount_reason": "new-teacher launch offer"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `package_uuid` | string | yes | must exist in `subscription_packages` (not retired) |
| `price_minor` | integer | no | ≥ 0 — overrides the plan price for this tenant (else the plan price) |
| `trial_days` | integer | no | 0–365 — overrides the plan trial (else the plan's `trial_days`) |
| `discount_reason` | string | no | max 255 — stored in the subscription `meta` |

**Behaviour:** status is `trialing` when the effective trial is > 0, else `active`. `renews_at` = (trial end, else now) + one interval.

**Response 201** — a `TenantSubscriptionResource` (with embedded `package`).

**Errors:** `422` (unknown/retired package, bad price) · `404` (unknown tenant / off host) · `403` · `401`.

---

## Teacher — my subscription

### `GET /v1/teacher/subscription`

**Purpose:** The resolved tenant's current plan, its limits, and current usage against them. Read-only.

**Auth:** 🧑‍🏫 Teacher · **Middleware:** `tenant` group → `auth:sanctum` → `active` → `role:teacher`

**Request headers:** standard tenant-scoped headers (`Host` = tenant domain, or `X-Tenant` in dev; `Authorization: Bearer`).

**Response 200**

```json
{
  "data": {
    "subscription": {
      "uuid": "1f7c...",
      "status": "active",
      "price_minor": 150000,
      "currency": "EGP",
      "started_at": "2026-07-16T10:00:00+00:00",
      "trial_ends_at": null,
      "renews_at": "2026-08-16T10:00:00+00:00",
      "ends_at": null,
      "package": { "uuid": "…", "slug": "growth", "…": "…full PackageResource…" }
    },
    "usage": {
      "max_students":   { "limit": 100,  "used": 1, "remaining": 99 },
      "max_courses":    { "limit": 10,   "used": 0, "remaining": 10 },
      "storage_mb":     { "limit": 5000, "used": 0, "remaining": 5000 },
      "max_assistants": { "limit": 2,    "used": 0, "remaining": 2 }
    }
  }
}
```

Notes:
- `subscription` is `null` when the academy has no current plan; `usage` is still returned (limits are `null` = unlimited, `remaining` `null`).
- A `null` `limit` means unlimited → `remaining` is `null`.
- `used` counts **active** student/assistant memberships and non-deleted courses for the tenant; `storage_mb.used` is `0` (deferred).
- Cross-tenant safe: a teacher only ever sees their own tenant's subscription (explicit `tenant_id` filter).

**Errors:** `403` (not a teacher / inactive membership) · `404` (unregistered host) · `401`.
