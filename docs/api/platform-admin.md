# Platform Admin Module

> The Platform Admin module is the operator console for the whole platform (M01, M17). Unlike every other API surface, it is **cross-tenant and NOT tenant-scoped**: its routes live under `/v1/admin/*` **outside** the `tenant` middleware group, so there is **no Host-based tenant resolution** and no per-tenant RLS binding. It owns the teacher-academy (tenant) lifecycle — create / list / view / update tenants and their owner — plus a cross-tenant reports overview and a global audit-log reader.

**How admin auth differs from the rest of the API:**
- **Host-pinned to the admin console.** The `/admin/*` group runs a `central` middleware (`EnsureCentralHost`) **ahead of auth**: the request `Host` must be a central host — `admin.<base_domain>` (default `admin.elameed.app`), the base-domain apex, or a trusted local host in dev. A request on any **teacher academy domain** (subdomain or custom domain) is answered with **`404 not_found`** — identical to a nonexistent route — so the console can never be opened from a teacher's domain, and a valid platform-admin token cannot be replayed against a tenant host.
- Middleware is `central` -> `auth:sanctum` -> `admin` (the `admin` alias = `EnsurePlatformAdmin`, which rejects anyone whose `isPlatformAdmin()` is false with a `403`). There is **no** `tenant`, `active`, or `role` middleware.
- Admin resolution is **not** tenant-scoped: no Host-based *tenant* resolution, no per-tenant RLS binding, and **no `X-Tenant` header**. The admin acts across all academies **only through `/admin/*`** (cross-tenant reports + tenant CRUD). Tenant *targeting*, where supported, is done with the `?tenant=` **query param** (audit logs) or the `{tenant:uuid}` **path** binding (tenant CRUD), not a header.
- **No implicit access to tenant-scoped routes.** A platform-admin token carries no role or membership inside any academy: presented to a tenant-scoped route (e.g. `/teacher/*`, `/me`) on a tenant host it is refused with `403` by the `active` / `role` gates. Admin power is exercised *exclusively* via `/admin/*` on the admin host — there is no admin override on tenant routes.
- Tenants bind by `uuid` on the path (`{tenant:uuid}`), never by internal id.

> **Common error (all admin endpoints):** `404 not_found` when the request is not on the admin host — the `central` gate runs before `auth:sanctum`, so an off-host request is refused before the token is even read.

Money is integer minor units (`*_minor`), base currency EGP. Timestamps are ISO-8601 UTC.

## Models & artifacts

- The **`Tenant`** model is owned by the **Tenancy** module (`app/Modules/Tenancy/Models/Tenant.php`) — a global registry row (not tenant-scoped), soft-deletes, `status` cast to the `TenantStatus` enum, and a `domains()` relation. This module owns no Eloquent models of its own; it contributes:
  - **`AdminTenantResource`** — the **lightweight** tenant row used by the list (`index`) and create (`store`): `uuid`, `slug`, `name`, `status`, `owner_user_id`, `primary_host` (the `is_primary` domain host, only when `domains` is loaded), `created_at`.
  - **`TenantInsights`** service — builds the **full 360** returned by `show` (tenant + owner + branding + subscription/usage + stats). It reads other modules' data cross-tenant with explicit `tenant_id` filters (Billing `SubscriptionService`/`PackageUsage`, Catalog `Course`, Commerce `Enrollment`, Wallet `LedgerEntry`, Tenancy `TeacherProfile`).
  - **`StoreTenantRequest`** / **`UpdateTenantRequest`** — FormRequests (field tables below).
  - **`EnsurePlatformAdmin`** — the `admin` middleware gate.
- **`TenantStatus`** enum values: `active`, `suspended`, `under_review`, `expired`.
- `GET /v1/admin/audit-logs` is served by the **Reporting** module's `AuditLogController@admin` (cross-tenant variant of the audit reader).

---

## Endpoints

> **Also under `/admin/*`:** teacher subscription **packages** and tenant plan **assignment** (`/admin/packages`, `/admin/tenants/{uuid}/subscription`) share this same host-pinned admin group but are owned by the **Billing** module — see [`billing.md`](billing.md).

### Tenants

#### `GET /v1/admin/tenants`

**Purpose:** List all tenant academies across the platform, newest first, paginated 30 per page. Each row includes its primary host.

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin` (outside the `tenant` group — no Host tenant resolution)

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Accept | yes | `application/json` |

> No per-tenant `Host` resolution and no `X-Tenant` header for admin routes — but the request **must** be on the central/admin host (the `central` gate; off-host → `404`). See "How admin auth differs" above.

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `page` | query | no | Page number (default 1). Page size fixed at 30. |

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "slug": "nile-academy",
      "name": "Nile Academy",
      "status": "active",
      "owner_user_id": 5501,
      "primary_host": "nile-academy.elameed.app",
      "created_at": "2026-06-02T11:14:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 30,
    "from": 1,
    "to": 30,
    "total": 74
  }
}
```

**Errors:**
- `403` — caller is not a platform admin (`EnsurePlatformAdmin`).
- `401 unauthenticated` — missing/invalid bearer token.

---

#### `POST /v1/admin/tenants`

**Purpose:** Provision a new tenant academy. Creates the `Tenant`, auto-creates a primary **subdomain** domain (`<slug>.<base_domain>`, default `elameed.app`), and optionally provisions an owner (teacher) user + active `TenantUser` membership in one transaction.

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body**

```json
{
  "name": "Nile Academy",
  "slug": "nile-academy",
  "status": "active",
  "owner": {
    "name": "Ahmed Teacher",
    "phone": "+201001234567",
    "email": "owner@nile-academy.com",
    "password": "Str0ngPass!"
  }
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `slug` | string | yes | max 100, regex `^[a-z0-9-]+$`, unique in `tenants.slug`, and **not a reserved slug** — `admin`, `www`, `api`, `app`, `mail`, `platform`, `central` by default (configurable via `TENANCY_RESERVED_SLUGS`); a reserved slug's subdomain would collide with a central host |
| `status` | string | no | one of `active`, `suspended`, `under_review`, `expired` (defaults to `active`) |
| `owner` | object | no | when present, provisions the academy owner |
| `owner.name` | string | required with `owner` | max 255 |
| `owner.phone` | string | required with `owner` | max 20 — matched via `firstOrCreate` on `phone` |
| `owner.email` | string | no | valid email, max 255 |
| `owner.password` | string | required with `owner` | min 8 |

Notes: the owner is created with `firstOrCreate` on `phone` (existing users reused; new ones get `phone_verified_at = now`); a teacher `TenantUser` membership is created active, and `tenant.owner_user_id` is set.

**Response 201**

```json
{
  "data": {
    "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
    "slug": "nile-academy",
    "name": "Nile Academy",
    "status": "active",
    "owner_user_id": 5501,
    "primary_host": "nile-academy.elameed.app",
    "created_at": "2026-07-15T10:00:00+00:00"
  }
}
```

**Errors:**
- `422` — validation failure (missing `name`/`slug`, bad slug format, duplicate or reserved slug, invalid `status`, incomplete `owner`).
- `403` — not a platform admin.
- `401 unauthenticated`.

---

#### `GET /v1/admin/tenants/{tenant:uuid}`

**Purpose:** The full cross-tenant **360 view** of one academy — the tenant, its **owner teacher**, branding, current **subscription + usage**, and activity **stats**. This is the admin's "all information about a teacher and their tenant" surface. (The list endpoint at `GET /admin/tenants` stays lightweight; this one is the deep view.)

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `tenant` | path | yes | Tenant **uuid** (route binds by `uuid`, not id). |

**Request body:** None

**Response 200**

```json
{
  "data": {
    "tenant": {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "slug": "nile-academy",
      "name": "Nile Academy",
      "status": "active",
      "trial_ends_at": null,
      "created_at": "2026-06-02T11:14:00+00:00",
      "domains": [
        { "host": "nile-academy.elameed.app", "type": "subdomain", "is_primary": true }
      ]
    },
    "owner": {
      "uuid": "6b1f2e30-…",
      "name": "Ahmed Teacher",
      "phone": "01500000001",
      "email": "owner@nile-academy.com",
      "locale": "ar",
      "created_at": "2026-06-02T11:14:00+00:00"
    },
    "branding": {
      "logo_url": "https://…/logo.png",
      "cover_url": "https://…/cover.png",
      "primary_color": "#1D4ED8",
      "secondary_color": "#9333EA",
      "bio": "…",
      "contact": { "phone": "01500000001", "email": "…", "address": "…" },
      "socials": { "youtube": "…" },
      "layout": "classic"
    },
    "subscription": {
      "uuid": "1f7c…",
      "status": "active",
      "price_minor": 150000,
      "currency": "EGP",
      "started_at": "2026-07-16T10:00:00+00:00",
      "trial_ends_at": null,
      "renews_at": "2026-08-16T10:00:00+00:00",
      "ends_at": null,
      "package": { "slug": "growth", "…": "…full PackageResource…" }
    },
    "usage": {
      "max_students":   { "limit": 2000,  "used": 412, "remaining": 1588 },
      "max_courses":    { "limit": 30,    "used": 12,  "remaining": 18 },
      "storage_mb":     { "limit": 50000, "used": 0,   "remaining": 50000 },
      "max_assistants": { "limit": 3,     "used": 1,   "remaining": 2 }
    },
    "stats": {
      "students": 412,
      "assistants": 1,
      "parents": 30,
      "courses": 12,
      "published_courses": 9,
      "enrollments": 1043,
      "gross_earnings_minor": 918400000
    }
  }
}
```

Notes:
- `owner`, `branding`, and `subscription` are each `null` when absent (no owner assigned / no profile yet / no plan).
- `usage` mirrors the Billing teacher view: `used` counts **active** student/assistant memberships and non-deleted courses; a `null` `limit` = unlimited (`remaining` `null`); `storage_mb.used` is `0` (deferred).
- `stats.gross_earnings_minor` = sum of this tenant's `teacher_earnings` credit ledger entries (integer minor units, EGP).
- All counts are computed cross-tenant with an explicit `tenant_id` filter (admin runs outside the tenant scope).

**Errors:**
- `404` — no tenant with that uuid (or request not on the admin host).
- `403` — not a platform admin.
- `401 unauthenticated`.

---

#### `PUT /v1/admin/tenants/{tenant:uuid}`

**Purpose:** Update a tenant's `name` and/or lifecycle `status` (e.g. suspend/expire an academy). The change is recorded to the audit log as `tenant.updated`.

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `tenant` | path | yes | Tenant **uuid**. |

**Request body**

```json
{
  "name": "Nile Academy (Updated)",
  "status": "suspended"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | no (`sometimes`) | max 255 |
| `status` | string | no (`sometimes`) | one of `active`, `suspended`, `under_review`, `expired` |

> `slug` and `owner` are **not** editable via this endpoint.

**Response 200**

```json
{
  "data": {
    "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
    "slug": "nile-academy",
    "name": "Nile Academy (Updated)",
    "status": "suspended",
    "owner_user_id": 5501,
    "primary_host": "nile-academy.elameed.app",
    "created_at": "2026-06-02T11:14:00+00:00"
  }
}
```

**Errors:**
- `422` — invalid `status` value or `name` too long.
- `404` — no tenant with that uuid.
- `403` — not a platform admin.
- `401 unauthenticated`.

---

### Reports & audit

#### `GET /v1/admin/reports/overview`

**Purpose:** Cross-tenant platform totals for the operator dashboard (FR-M17-01). All queries deliberately drop tenant scoping (`withoutGlobalScopes()` where needed).

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "teachers": 74,
    "students": 18432,
    "courses": 512,
    "gross_earnings_minor": 918400000,
    "tenants_by_status": {
      "active": 61,
      "suspended": 8,
      "under_review": 3,
      "expired": 2
    }
  }
}
```

Notes:
- `teachers` = total tenant count (one academy per teacher).
- `students` = distinct `user_id` count across all `TenantUser` rows with `role = student`.
- `courses` = global course count (all tenants).
- `gross_earnings_minor` = sum of all `teacher_earnings` credit ledger entries platform-wide (integer minor units, EGP).
- `tenants_by_status` = object keyed by `TenantStatus` value -> count.

**Errors:**
- `403` — not a platform admin.
- `401 unauthenticated`.

---

#### `GET /v1/admin/audit-logs`

**Purpose:** Read the audit trail across all tenants (M18, admin scope), newest first, paginated 50 per page. Optionally narrow to one tenant with the `?tenant=` query param. Served by the Reporting module's `AuditLogController@admin`.

**Auth:** 🛡️ Platform admin
**Middleware:** `central` -> `auth:sanctum` -> `admin`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `tenant` | query | no | Filter to a single tenant's entries by **`tenant_id`** (the internal id column, not uuid/slug). Omit for all tenants. |
| `page` | query | no | Page number (default 1). Page size fixed at 50. |

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "action": "tenant.updated",
      "actor": "Platform Ops",
      "subject_type": "tenant",
      "subject_id": 42,
      "meta": {
        "tenant": "nile-academy",
        "changes": { "status": "suspended" }
      },
      "ip": "41.90.3.211",
      "created_at": "2026-07-15T10:03:12+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 12,
    "total": 587
  }
}
```

Notes:
- Same row/meta shape as the teacher audit-log endpoint; the difference is scope — no tenant is forced, and cross-tenant rows (nullable `tenant_id`) are visible.
- `actor` is the actor's `name` string or `null`. The list `meta` is the custom shape (`current_page`, `last_page`, `total` only).

**Errors:**
- `403` — not a platform admin.
- `401 unauthenticated`.
