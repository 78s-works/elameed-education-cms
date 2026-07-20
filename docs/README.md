# Elameed Education — API & Modules Documentation

Code-accurate reference for the Elameed Education API (Laravel 13 + Sanctum,
multi-tenant SaaS). Generated from the source in `app/Modules/*` — controllers,
FormRequests, API Resources, models, and enums — and cross-checked against the
Postman collection in [`postman/`](../postman).

| | |
|---|---|
| **Stack** | Laravel 13, PHP 8.3, Laravel Sanctum (personal access tokens) |
| **Base URL** | `https://<tenant-host>/api/v1` (per-tenant host, or `*.elameed.app` subdomain) |
| **Format** | JSON only (`Accept: application/json`) |
| **Architecture** | Modular monolith — one module per bounded context under `app/Modules` |

---

## How to read these docs

1. **[`01_Modules.md`](01_Modules.md)** — the module map: what each module owns
   (purpose, models, services, endpoint count) and how they fit together.
2. **[`api/`](api)** — one endpoint-reference file per module. Every endpoint
   documents its **auth**, **middleware**, **request headers**, **path/query
   params**, **request body**, **response JSON**, and **notable errors**.
3. **[`design/`](design)** — forward-looking design notes for changes **not yet
   implemented**. Clearly marked as proposals; the `api/` files remain the source
   of truth for current behaviour.

| Module | Endpoints | Reference |
|---|--:|---|
| Tenancy | 9 | [`api/tenancy.md`](api/tenancy.md) |
| Identity | 28 | [`api/identity.md`](api/identity.md) |
| Catalog | 23 | [`api/catalog.md`](api/catalog.md) |
| Media | 20 | [`api/media.md`](api/media.md) |
| Commerce | 4 | [`api/commerce.md`](api/commerce.md) |
| Wallet | 2 | [`api/wallet.md`](api/wallet.md) |
| Centers | 11 | [`api/centers.md`](api/centers.md) |
| Assessment | 13 | [`api/assessment.md`](api/assessment.md) |
| Engagement | 16 | [`api/engagement.md`](api/engagement.md) |
| Notifications | 2 | [`api/notifications.md`](api/notifications.md) |
| Reporting | 4 | [`api/reporting.md`](api/reporting.md) |
| Platform Admin | 6 | [`api/platform-admin.md`](api/platform-admin.md) |
| Billing | 8 | [`api/billing.md`](api/billing.md) |
| **Total** | **146** | |

> These docs describe the **implemented** behaviour. Where it diverges from the
> design-time spec in [`../docs (1)/04_API_Specification.md`](../../docs%20(1)/04_API_Specification.md),
> the per-module file documents what the code actually does and flags the gap.

### Design notes

Design rationale for notable features (each note records the decision, not just the API).

| Design note | Status | Summary |
|---|---|---|
| [`design/lesson-video-sources.md`](design/lesson-video-sources.md) | ✅ Implemented | Dual-source lesson video — a lesson may hold **both** a protected upload and a YouTube link, with a teacher-controlled toggle for which one students see. Affects Catalog + Media. |

---

## Conventions (apply to every endpoint)

### Tenancy — how the tenant is resolved
Every `/api/v1` route (except the platform-host webhooks and `/admin/*`) runs
through the **`tenant` middleware group**:

```
EnsureRegisteredDomain  →  ResolveTenant  →  (route-model binding)
```

- The tenant is resolved from the **`Host` header** — a custom domain or an
  `*.elameed.app` subdomain registered to an **active** tenant.
- An unknown/suspended host is rejected **before** any tenant work happens.
- `ResolveTenant` binds the tenant and its **RLS session** *before* route-model
  binding, so bound models can never cross tenants.
- **Dev/tooling override:** `X-Tenant: <slug>` selects the tenant directly —
  only when `tenancy.allow_header_override` is enabled. Production resolves
  purely from `Host`.

### Authentication
- **Laravel Sanctum** personal access tokens: `Authorization: Bearer <token>`.
- Public endpoints (tenant context, catalogue browse, register, login, OTP,
  review index) need **no** token.
- Authenticated routes add `auth:sanctum` + **`active`** (`EnsureActiveMembership`
  — the caller must be a non-suspended member of the resolved tenant).
- Role-gated routes add **`role:teacher`** / **`role:parent`**
  (`EnsureTenantRole`). Assistants share the teacher surface with scoped
  permissions (P1.5).
- **Platform admin** (`/admin/*`) uses **`central`** (`EnsureCentralHost`, host-pins
  the console to the admin host — off-host → `404`) + `auth:sanctum` + **`admin`**
  (`EnsurePlatformAdmin`) and is **not** tenant-scoped. A platform-admin token has
  **no** implicit access to tenant-scoped routes.

### Standard request headers

| Header | When | Value |
|---|---|---|
| `Host` | tenant-scoped routes | tenant domain, e.g. `academy.elameed.app` |
| `X-Tenant` | dev/tooling only | tenant slug, e.g. `academy` (overrides Host) |
| `Accept` | always | `application/json` |
| `Authorization` | authenticated routes | `Bearer <sanctum-token>` |
| `Content-Type` | requests with a JSON body | `application/json` |
| `Content-Type` | file uploads (cover, attachment, landing media) | `multipart/form-data` |
| `Idempotency-Key` | money endpoints (**accepted, ignored in P1** — see [commerce](api/commerce.md)) | opaque client string |

Media streaming and gateway/processing webhooks use **different** auth (token in
URL, HMAC signature, or shared secret) and skip the `tenant` group entirely —
see [`api/media.md`](api/media.md) and [`api/commerce.md`](api/commerce.md).

### Response envelope
Success:
```json
{ "data": { /* object */ } }
```
Collections may add pagination metadata. **Two paginator shapes exist in the
codebase** (documented per endpoint):

- **Laravel default** (most resource collections) — full `meta`:
  ```json
  { "data": [ … ], "links": { "first","last","prev","next" },
    "meta": { "current_page","from","last_page","per_page","to","total" } }
  ```
- **Trimmed** (audit-log controller) — `meta: { current_page, last_page, total }` only.

Some list endpoints are deliberately **not** paginated (`.get()`), returning a
plain `data` array — noted where it applies.

Failure — the API error envelope (`App\Support\Http\ApiExceptionRenderer`):
```json
{ "error": { "code": "string", "message": "Arabic-friendly", "details": { } } }
```

### Money, dates, locale
- **Money** = integer **minor units** + a `currency` code (e.g. `amount_minor: 15000`, `currency: "EGP"` = 150.00 EGP). Ledger balances are **derived**, never stored.
- **Timestamps** are ISO-8601 UTC.
- **Arabic** content is returned as-is (UTF-8).

### Status codes

| Code | Meaning |
|---|---|
| `200` / `201` / `202` | OK / created / accepted (async, e.g. register→OTP) |
| `204` | No content (deletes) |
| `401` | Unauthenticated → `code: unauthenticated` |
| `403` | Forbidden (role / inactive / access check) → `code: forbidden` |
| `404` | Not found (incl. cross-tenant resources) → `code: not_found` |
| `409` | Conflict (state machine, idempotency) |
| `422` | Validation error → `code: validation_error`, `details` = field errors |
| `429` | Rate-limited (`throttle:otp`, `throttle:auth`, `throttle:120,1`) |
| `5xx` | Server error (internals never leaked in production) |

> Only `401/403/404/405/429` get semantic `code` values from the renderer; other
> 4xx thrown as `HttpException` fall through to `code: "error"` with the
> exception's message. Laravel `ValidationException` always maps to `422`
> `validation_error`.

### Versioning
URI-versioned under `/v1`. Breaking changes ship under `/v2`.

---

## Middleware aliases (from `bootstrap/app.php`)

| Alias / group | Class | Effect |
|---|---|---|
| `tenant` (group) | `EnsureRegisteredDomain` + `ResolveTenant` | Reject unknown host, resolve tenant + bind RLS |
| `auth:sanctum` | Sanctum guard | Require a valid bearer token |
| `active` | `EnsureActiveMembership` | Require an active membership in the tenant |
| `role:<role>` | `EnsureTenantRole` | Require that tenant role (`teacher`/`parent`/…) |
| `admin` | `EnsurePlatformAdmin` | Require the platform-admin flag |
| `signed` | Laravel signed-URL | Validate a signed upload URL |
| `throttle:otp` / `throttle:auth` / `throttle:120,1` | Rate limiters | OTP/auth/webhook rate limits |
