# Tenancy Module

> The Tenancy module is the platform's multi-tenant backbone. It maps an incoming **Host** (custom domain or `*.elameed.app` subdomain) to a tenant academy, binds that tenant for the rest of the request (RLS/`BelongsToTenant` scoping), and exposes the tenant's public identity, branding/theme, and teacher-authored landing page to the SPA. It also owns the teacher-facing endpoints for editing branding (profile) and the landing page (layout + typed sections), plus a media upload helper for landing images. Landing content follows the **LANDING_CONTRACT_V2** contract: a fixed catalog of typed sections where two types (`courses`, `testimonials`) are resolved server-side into real items.

## Models

- **`Tenant`** — A teacher academy; the **global** tenant-registry row (NOT tenant-scoped, no `BelongsToTenant`/RLS). Has `uuid`, `slug`, `name`, `status` (enum), soft-deletes, and relations to `domains`, `teacherProfile`, and `owner`.
- **`TenantDomain`** — Host → tenant mapping row. **Global** (read during resolution, before any tenant scope exists). Holds `host`, `type` (subdomain|custom), `is_primary`, and Cloudflare-for-SaaS SSL fields.
- **`TeacherProfile`** — Per-tenant branding + landing configuration; one row per tenant and the **first** tenant-scoped model (`BelongsToTenant` filters every query and auto-fills `tenant_id`). Stores `logo_url`, `cover_url`, `primary_color`, `secondary_color`, `bio`, `contact` (json), `socials` (json), `layout`, `landing_sections` (json), `hide_ranking`.

## Enums

- **`TenantStatus`** (`app/Modules/Tenancy/Enums`): `active`, `suspended`, `under_review`, `expired`. Only `active` is operational (`isOperational()`), i.e. permits teacher-side actions.
- **`TenantDomainType`** (`app/Modules/Tenancy/Enums`): `subdomain` (Phase 1), `custom` (Phase 1.5, via Cloudflare for SaaS).

## Services / Support

- **`TenantContext`** — Request-scoped singleton holding the resolved tenant (`hasTenant()`, `tenant()`, `tenantOrFail()`). Set by `ResolveTenant`.
- **`TenantResolver`** — Maps a request to a tenant: (1) `X-Tenant` header override (dev/tooling only, when `tenancy.allow_header_override`), (2) exact host match in `tenant_domains`, (3) `<label>.<base_domain>` subdomain → slug, else unresolved. Aggressively cached with negative caching for unknown hosts.
- **`LandingResolver`** — Resolves the teacher's stored landing config into the fully rendered public payload: normalizes `layout`, resolves dynamic `courses`/`testimonials` sections to real `items`, and derives the anchor `nav`.
- **`LandingSchema`** (Support) — The v2 landing contract: layout list, section-type catalog, per-type content/config validation rules, `sanitize()` for saving, and `defaults()` seed. See below for the section-type table.
- **`EnsureRegisteredDomain`** + **`ResolveTenant`** (Middleware) — the `tenant` middleware group: the first hard-gates unregistered/inactive hosts (404/403), the second resolves + binds the tenant and RLS session.

### Landing section types (`LandingSchema::TYPES`)

`hero`, `stats`, `features`, `about`, `steps`, `courses`, `testimonials`, `packages`, `cta`, `contact`. Layouts: `classic` (default), `grid`, `spotlight`.

- **Dynamic** (`courses`, `testimonials`): the teacher stores a `config` block; the public endpoint resolves it into `items`.
- **Item-preserved** (`stats`, `features`, `steps`, `packages`): only `title`/`subtitle` are editable this milestone; their `content.items` are carried over from the last save.

---

## Endpoints

### `GET /tenant/context`

**Purpose:** Resolve the current host to a tenant and return its identity, status, branding/theme, locale, and enabled feature flags — the payload the SPA loads on boot. Landing content is served separately by `GET /tenant/landing`.

**Auth:** 🔓 Public (tenant middleware only)
**Middleware:** `tenant`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
    "slug": "mrkhaled",
    "name": "أكاديمية مستر خالد",
    "status": "active",
    "branding": {
      "logo_url": "https://cdn.elameed.app/landing/12/logo.png",
      "cover_url": "https://cdn.elameed.app/landing/12/cover.jpg",
      "primary_color": "#1E88E5",
      "secondary_color": "#FFB300",
      "bio": "مدرّس فيزياء بخبرة 10 سنوات.",
      "socials": {
        "facebook": "https://facebook.com/mrkhaled",
        "youtube": "https://youtube.com/@mrkhaled"
      }
    },
    "locale": {
      "default": "ar",
      "supported": ["ar", "en"]
    },
    "features": []
  }
}
```

Notes: `branding` fields are `null` until the teacher sets them; `socials` is an empty object `{}` when unset. `status` is one of `active`, `suspended`, `under_review`, `expired`. `features` is currently always `[]` (per-tenant flags TODO).

**Errors:**
- `404 tenant_not_found` — the host resolved to no tenant (envelope: `{ "error": { "code": "tenant_not_found", "message": "لا يوجد حساب مرتبط بهذا العنوان." } }`).
- `404` — host is not a registered tenant domain (thrown earlier by `EnsureRegisteredDomain`).
- `403` — host maps to a non-active (suspended/expired) tenant (`EnsureRegisteredDomain`).

---

### `GET /tenant/landing`

**Purpose:** Return the fully resolved public landing page for the SPA: normalized `layout`, anchor `nav` links, and the ordered visible `sections` with dynamic `courses`/`testimonials` sections resolved into real `items`. Auth is **optional** — if a bearer token is present, each resolved course item carries an `enrolled` flag for that student.

**Auth:** 🔓 Public, optional auth (bearer token enriches `enrolled`)
**Middleware:** `tenant`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | optional (Bearer token → `enrolled`) | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "layout": "classic",
    "nav": {
      "links": [
        { "label": "من نحن", "target": "#about" },
        { "label": "الكورسات", "target": "#courses" },
        { "label": "آراء الطلاب", "target": "#testimonials" },
        { "label": "تواصل معنا", "target": "#contact" }
      ]
    },
    "sections": [
      {
        "key": "hero",
        "type": "hero",
        "visible": true,
        "order": 1,
        "content": {
          "eyebrow": "أهلاً بك",
          "title_html": "أتقن <span>الفيزياء</span>",
          "description": "دروس مصمّمة لصفّك الدراسي.",
          "note": "دفعة جديدة قريبًا",
          "primary_cta": { "label": "ابدأ الآن" },
          "secondary_cta": { "label": "تصفّح الكورسات" },
          "teacher": {
            "name": "مستر أحمد",
            "role": "مدرّس فيزياء",
            "image_url": "https://cdn.elameed.app/landing/12/teacher.jpg",
            "card_stats": [{ "value": "10k+", "label": "طالب" }]
          },
          "chips": [{ "text": "معتمد", "type": "green" }]
        }
      },
      {
        "key": "courses",
        "type": "courses",
        "visible": true,
        "order": 5,
        "content": { "title": "الكورسات", "subtitle": "" },
        "items": [
          {
            "id": 41,
            "uuid": "3f1c9a2b-8d47-4e10-9b6a-1c2d3e4f5061",
            "slug": "physics-grade-3",
            "title": "فيزياء الثالث الثانوي",
            "cover_url": "https://cdn.elameed.app/covers/41.jpg",
            "grade": "الصف الثالث الثانوي",
            "type": "online",
            "price": { "amount_minor": 25000, "currency": "EGP" },
            "is_free": false,
            "lessons_count": 24,
            "duration_label": "12h 30m",
            "rating": 4.8,
            "students_count": 312,
            "enrolled": false
          }
        ]
      },
      {
        "key": "testimonials",
        "type": "testimonials",
        "visible": true,
        "order": 7,
        "content": { "title": "آراء الطلاب", "subtitle": "" },
        "items": [
          {
            "id": 8,
            "student_name": "سارة محمد",
            "course_title": "فيزياء الثالث الثانوي",
            "rating": 5,
            "comment": "شرح ممتاز وسهل.",
            "created_at": "2026-05-02T14:31:00+00:00"
          }
        ]
      }
    ]
  }
}
```

Notes:
- `layout` is normalized to one of `classic|grid|spotlight` (falls back to `classic`).
- `nav.links` are derived from visible, nav-worthy section types (`about`, `features`, `courses`, `steps`, `testimonials`, `packages`, `contact`); `target` is `#<section key>`, `label` falls back to a capitalized type name when the section has no `content.title`.
- Only `courses` and `testimonials` sections carry an `items` array; static sections do not.
- `enrolled` is `true` only for the authenticated student's active enrollments; anonymous requests always get `false`.
- Prices are integer minor units + `currency`; timestamps are ISO-8601 UTC.

**Errors:**
- `404` / `403` — same host-gate errors as `GET /tenant/context` (`EnsureRegisteredDomain`). A required-but-missing tenant surfaces as a server error (`tenantOrFail`).

---

### `GET /teacher/profile`

**Purpose:** Return the current tenant's branding profile for the teacher's editor (FR-M02-03). Operates on the tenant's single `teacher_profiles` row (never written by GET).

**Auth:** 🔒 `auth:sanctum` + `active` + `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "logo_url": "https://cdn.elameed.app/landing/12/logo.png",
    "cover_url": "https://cdn.elameed.app/landing/12/cover.jpg",
    "primary_color": "#1E88E5",
    "secondary_color": "#FFB300",
    "bio": "مدرّس فيزياء بخبرة 10 سنوات.",
    "contact": {
      "phone": "+201001234567",
      "email": "teacher@example.com",
      "whatsapp": "+201001234567",
      "address": "12 شارع التحرير، القاهرة"
    },
    "socials": {
      "facebook": "https://facebook.com/mrkhaled",
      "youtube": "https://youtube.com/@mrkhaled"
    }
  }
}
```

Notes: unset `contact` / `socials` serialize as empty objects `{}`; the other fields are `null` until set.

**Errors:**
- `401` — missing/invalid bearer token.
- `403` — authenticated user is not an active `teacher` member of this tenant.

---

### `PUT /teacher/profile`

**Purpose:** Upsert the current tenant's branding profile (FR-M02-03). Always responds `200` (upsert, never `201`).

**Auth:** 🔒 `auth:sanctum` + `active` + `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body**

```json
{
  "logo_url": "https://cdn.example.com/logo.png",
  "cover_url": "https://cdn.example.com/cover.jpg",
  "primary_color": "#1E88E5",
  "secondary_color": "#FFB300",
  "bio": "Physics teacher with 10 years of experience.",
  "contact": {
    "phone": "+201001234567",
    "email": "teacher@example.com",
    "whatsapp": "+201001234567",
    "address": "12 Tahrir St, Cairo"
  },
  "socials": {
    "facebook": "https://facebook.com/teacher",
    "youtube": "https://youtube.com/@teacher"
  }
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `logo_url` | string | no | nullable, valid URL, max 2048 |
| `cover_url` | string | no | nullable, valid URL, max 2048 |
| `primary_color` | string | no | nullable, hex color `#RRGGBB` or `#RRGGBBAA` |
| `secondary_color` | string | no | nullable, hex color `#RRGGBB` or `#RRGGBBAA` |
| `bio` | string | no | nullable, max 2000 |
| `contact` | object | no | nullable array/object |
| `contact.phone` | string | no | nullable, max 32 |
| `contact.email` | string | no | nullable, valid email, max 255 |
| `contact.whatsapp` | string | no | nullable, max 32 |
| `contact.address` | string | no | nullable, max 500 |
| `socials` | object | no | nullable object; values are URLs |
| `socials.*` | string | no | nullable, valid URL, max 2048 |

**Response 200:** Same shape as `GET /teacher/profile` (the updated `TeacherProfileResource`).

**Errors:**
- `422` — validation failure (e.g. bad hex color, invalid URL/email). Error envelope with `details` per field.
- `401` / `403` — as above.

---

### `GET /teacher/landing`

**Purpose:** Return the teacher's **editable** landing state (LANDING_CONTRACT_V2 authoring shape): `layout`, derived `nav`, and **all** sections (including hidden) with their `content` and — for dynamic sections — the raw `config` (NOT resolved `items`), so the editor renders controls, not preview data. Falls back to `LandingSchema::defaults()` when nothing is saved yet.

**Auth:** 🔒 `auth:sanctum` + `active` + `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "layout": "classic",
    "nav": {
      "links": [
        { "label": "الكورسات", "target": "#courses" },
        { "label": "آراء الطلاب", "target": "#testimonials" },
        { "label": "تواصل معنا", "target": "#contact" }
      ]
    },
    "sections": [
      {
        "key": "hero",
        "type": "hero",
        "visible": true,
        "order": 1,
        "content": {
          "eyebrow": "",
          "title_html": "",
          "description": "",
          "note": "",
          "primary_cta": { "label": "ابدأ الآن" },
          "secondary_cta": { "label": "تصفّح الكورسات" },
          "teacher": { "name": "", "role": "", "image_url": null, "card_stats": [] },
          "chips": []
        }
      },
      {
        "key": "courses",
        "type": "courses",
        "visible": true,
        "order": 5,
        "content": { "title": "الكورسات", "subtitle": "" },
        "config": { "source": "featured", "category_id": null, "course_ids": [], "limit": 6 }
      },
      {
        "key": "testimonials",
        "type": "testimonials",
        "visible": true,
        "order": 7,
        "content": { "title": "آراء الطلاب", "subtitle": "" },
        "config": { "source": "latest", "min_rating": 0, "limit": 6 }
      }
    ]
  }
}
```

Notes: unlike `GET /tenant/landing`, dynamic sections here expose `config` and NOT resolved `items`. Sections are returned as stored (all keys, including `visible: false`).

**Errors:** `401` / `403` — as above.

---

### `PUT /teacher/landing`

**Purpose:** Author the landing page (FR-M02-04): choose a `layout` and submit the full ordered list of typed sections with content. The server **sanitizes** input (keeps known types/fields only), preserves non-editable `items` from the previous save for item-preserved types, cleans dynamic `config`, and sanitizes `hero.title_html` (only bare `<span>` allowed). Always responds `200`.

**Auth:** 🔒 `auth:sanctum` + `active` + `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body**

```json
{
  "layout": "classic",
  "sections": [
    {
      "key": "hero",
      "type": "hero",
      "visible": true,
      "order": 1,
      "content": {
        "eyebrow": "Welcome",
        "title_html": "Master <span>Physics</span>",
        "description": "Top-rated lessons for your grade.",
        "note": "New batch starting soon",
        "primary_cta": { "label": "Start now" },
        "secondary_cta": { "label": "Browse courses" },
        "teacher": {
          "name": "Mr. Ahmed",
          "role": "Physics Teacher",
          "image_url": "https://cdn.example.com/teacher.jpg",
          "card_stats": [{ "value": "10k+", "label": "Students" }]
        },
        "chips": [{ "text": "Certified", "type": "green" }]
      }
    },
    {
      "key": "courses",
      "type": "courses",
      "visible": true,
      "order": 3,
      "content": { "title": "My Courses", "subtitle": "Pick a track" },
      "config": { "source": "featured", "category_id": null, "course_ids": [], "limit": 6 }
    }
  ]
}
```

**Top-level fields**

| Field | Type | Required | Rules |
|---|---|---|---|
| `layout` | string | no (`sometimes`) | one of `classic`, `grid`, `spotlight` |
| `sections` | array | **yes** | max 30 items |
| `sections.*.key` | string | **yes** | max 40 |
| `sections.*.type` | string | **yes** | one of `hero`, `stats`, `features`, `about`, `steps`, `courses`, `testimonials`, `packages`, `cta`, `contact` |
| `sections.*.visible` | boolean | **yes** | — |
| `sections.*.order` | integer | no | nullable, min 1 (defaults to array position) |
| `sections.*.content` | object | no (`sometimes`) | validated per type (see below) |

**Per-type editable `content` fields**

| Type | Editable content fields |
|---|---|
| `hero` | `eyebrow`, `title_html` (bare `<span>` only), `description`, `note`, `primary_cta.label`, `secondary_cta.label`, `teacher.{name,role,image_url,card_stats[].{value,label}}`, `chips[].{text,type∈green\|red\|plain}` |
| `about` | `badge`, `title`, `body`, `image_url`, `points[]` |
| `cta` | `title`, `subtitle`, `cta.label` |
| `courses`, `testimonials`, `features`, `steps`, `packages`, `contact` | `title`, `subtitle` only |
| `stats` | none editable this milestone |

**Dynamic-section `config` fields**

| Type | Config fields |
|---|---|
| `courses` | `config.source` ∈ `featured\|all\|category\|selected` (required); `config.category_id` (int, nullable); `config.course_ids[]` (int, max 24); `config.limit` (1–24, default 6) |
| `testimonials` | `config.source` ∈ `latest\|top_rated` (required); `config.min_rating` (0–5); `config.limit` (1–24, default 6) |

Cross-field validation: for a `courses` section with `source=category`, `category_id` must be a category in this academy; with `source=selected`, all `course_ids` must belong to this teacher (otherwise `422`).

**Response 200:** Same shape as `GET /teacher/landing` (the freshly saved `TeacherLandingResource`).

**Errors:**
- `422` — validation failure (unknown type, too many sections, invalid config, category/courses not owned, etc.).
- `401` / `403` — as above.

---

### `POST /teacher/landing/media`

**Purpose:** Upload a landing/branding image (logo, hero background, avatars) to the **public** disk and return its public URL, for use in profile/landing fields. Files are stored under `landing/<tenant_id>/`.

**Auth:** 🔒 `auth:sanctum` + `active` + `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `multipart/form-data` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body** (`multipart/form-data`)

| Field | Type | Required | Rules |
|---|---|---|---|
| `file` | file | **yes** | image MIME: `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/svg+xml`; max 5120 KB (5 MB) |

**Response 200**

```json
{
  "data": {
    "url": "https://cdn.elameed.app/storage/landing/12/9aX7bQ...png"
  }
}
```

**Errors:**
- `422` — missing file, wrong MIME type, or over 5 MB.
- `401` / `403` — as above.

---

## Tenancy & error conventions (applies to every endpoint)

- **Base path:** all routes are under `/api/v1`.
- **Host gate:** every route runs through the `tenant` middleware group — `EnsureRegisteredDomain` (rejects unknown host → `404`, inactive tenant → `403`) then `ResolveTenant` (binds the tenant + RLS session). The tenant is resolved from the **Host** header (custom domain or `*.elameed.app` subdomain). An `X-Tenant: <slug>` header overrides only when `tenancy.allow_header_override` is enabled (local/tooling).
- **Success envelope:** `{ "data": ... }`.
- **Error envelope:** `{ "error": { "code": "...", "message": "...", "details": { } } }`.
- **Money:** integer minor units + `currency`. **Timestamps:** ISO-8601 UTC. **Arabic content:** UTF-8 as-is.
- **Auth:** Laravel Sanctum, `Authorization: Bearer <token>`. `role:teacher` routes additionally require `auth:sanctum` + `active` (active tenant membership; a suspended member is blocked here).
