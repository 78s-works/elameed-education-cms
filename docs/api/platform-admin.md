# Platform Admin Module

> The Platform Admin module is the operator console for the whole platform (M01, M17). Unlike every other API surface, it is **cross-tenant and NOT tenant-scoped**: its routes live under `/v1/admin/*` **outside** the `tenant` middleware group, so there is **no Host-based tenant resolution** and no per-tenant RLS binding. It owns the teacher-academy (tenant) lifecycle — create / list / view / update tenants and their owner — plus a cross-tenant reports overview and a global audit-log reader.

**How admin auth differs from the rest of the API:**
- Middleware is `auth:sanctum` + `admin` (the `admin` alias = `EnsurePlatformAdmin`, which rejects anyone whose `isPlatformAdmin()` is false with a `403`). There is **no** `tenant`, `active`, or `role` middleware.
- No `Host` tenant requirement and **no `X-Tenant` header** — the admin acts across all academies. Tenant *targeting*, where supported, is done with the `?tenant=` **query param** (audit logs) or the `{tenant:uuid}` **path** binding (tenant CRUD), not a header.
- Tenants bind by `uuid` on the path (`{tenant:uuid}`), never by internal id.

Money is integer minor units (`*_minor`), base currency EGP. Timestamps are ISO-8601 UTC.

## Models & artifacts

- The **`Tenant`** model is owned by the **Tenancy** module (`app/Modules/Tenancy/Models/Tenant.php`) — a global registry row (not tenant-scoped), soft-deletes, `status` cast to the `TenantStatus` enum, and a `domains()` relation. This module owns no Eloquent models of its own; it contributes:
  - **`AdminTenantResource`** — serializes a tenant to `uuid`, `slug`, `name`, `status`, `owner_user_id`, `primary_host` (the `is_primary` domain host, only when `domains` is loaded), `created_at`.
  - **`StoreTenantRequest`** / **`UpdateTenantRequest`** — FormRequests (field tables below).
  - **`EnsurePlatformAdmin`** — the `admin` middleware gate.
- **`TenantStatus`** enum values: `active`, `suspended`, `under_review`, `expired`.
- `GET /v1/admin/audit-logs` is served by the **Reporting** module's `AuditLogController@admin` (cross-tenant variant of the audit reader).

---

## Endpoints

### Tenants

#### `GET /v1/admin/tenants`

**Purpose:** List all tenant academies across the platform, newest first, paginated 30 per page. Each row includes its primary host.

**Auth:** 🛡️ Platform admin
**Middleware:** `auth:sanctum` -> `admin` (outside the `tenant` group — no Host tenant resolution)

**Request headers**

| Header | Required | Example |
|---|---|---|
| Authorization | yes | `Bearer 7\|admin...` |
| Accept | yes | `application/json` |

> No `Host` tenant requirement and no `X-Tenant` header for admin routes.

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
**Middleware:** `auth:sanctum` -> `admin`

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
| `slug` | string | yes | max 100, regex `^[a-z0-9-]+$`, unique in `tenants.slug` |
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
- `422` — validation failure (missing `name`/`slug`, bad slug format, duplicate slug, invalid `status`, incomplete `owner`).
- `403` — not a platform admin.
- `401 unauthenticated`.

---

#### `GET /v1/admin/tenants/{tenant:uuid}`

**Purpose:** Fetch a single tenant academy by uuid, with its primary host.

**Auth:** 🛡️ Platform admin
**Middleware:** `auth:sanctum` -> `admin`

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
    "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
    "slug": "nile-academy",
    "name": "Nile Academy",
    "status": "active",
    "owner_user_id": 5501,
    "primary_host": "nile-academy.elameed.app",
    "created_at": "2026-06-02T11:14:00+00:00"
  }
}
```

**Errors:**
- `404` — no tenant with that uuid.
- `403` — not a platform admin.
- `401 unauthenticated`.

---

#### `PUT /v1/admin/tenants/{tenant:uuid}`

**Purpose:** Update a tenant's `name` and/or lifecycle `status` (e.g. suspend/expire an academy). The change is recorded to the audit log as `tenant.updated`.

**Auth:** 🛡️ Platform admin
**Middleware:** `auth:sanctum` -> `admin`

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
**Middleware:** `auth:sanctum` -> `admin`

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
**Middleware:** `auth:sanctum` -> `admin`

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
