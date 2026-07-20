# Tenancy Module

> The Tenancy module is the platform's multi-tenant backbone. It maps an incoming **Host** (custom domain or `*.elameed.app` subdomain) to a tenant academy, binds that tenant for the rest of the request (RLS/`BelongsToTenant` scoping), and exposes the tenant's public identity, branding/theme, and teacher-authored landing page to the SPA. It also owns the teacher-facing endpoints for editing branding (profile) and the landing page (layout + typed sections), plus a media upload helper for landing images. Landing content follows the **LANDING_CONTRACT_V2** contract: a fixed catalog of typed sections where two types (`courses`, `testimonials`) are resolved server-side into real items.
>
> **Per-section layout:** on top of the page-level `layout` (overall theme: `classic|grid|spotlight`), **every section carries its own `variant`** — one of **4 layouts defined per section type** (`LandingSchema::VARIANTS`). The teacher picks a section's variant from the editor, independently per section, so e.g. the `courses` section can render as a `carousel` while `testimonials` render as a `slider`. Variants are validated **per type** (a `courses` variant can't be set on a `hero`), and any section stored without a variant resolves to that type's default (the first variant listed).
>
> **Multi-language:** landing content is translatable. The teacher enables a set of `locales` (a subset of the platform-supported languages) and picks a `primary_locale`; each section's `content` is authored **per locale** (`{ "ar": {…}, "en": {…} }`). The public landing returns **all** enabled locales in one payload (the SPA switches client-side), with any untranslated section falling back to the primary locale. The teacher may also **add, remove, reorder, and duplicate** section instances — but only of the code-defined catalog types (no freeform/HTML sections).

## Models

- **`Tenant`** — A teacher academy; the **global** tenant-registry row (NOT tenant-scoped, no `BelongsToTenant`/RLS). Has `uuid`, `slug`, `name`, `status` (enum), soft-deletes, and relations to `domains`, `teacherProfile`, and `owner`.
- **`TenantDomain`** — Host → tenant mapping row. **Global** (read during resolution, before any tenant scope exists). Holds `host`, `type` (subdomain|custom), `is_primary`, and Cloudflare-for-SaaS SSL fields.
- **`TeacherProfile`** — Per-tenant branding + landing configuration; one row per tenant and the **first** tenant-scoped model (`BelongsToTenant` filters every query and auto-fills `tenant_id`). Stores `logo_url`, `cover_url`, `primary_color`, `secondary_color`, `bio`, `contact` (json), `socials` (json), `layout`, `landing_sections` (json, per-locale content), `locales` (json list of enabled languages), `primary_locale` (string), `hide_ranking`.

## Enums

- **`TenantStatus`** (`app/Modules/Tenancy/Enums`): `active`, `suspended`, `under_review`, `expired`. Only `active` is operational (`isOperational()`), i.e. permits teacher-side actions.
- **`TenantDomainType`** (`app/Modules/Tenancy/Enums`): `subdomain` (Phase 1), `custom` (Phase 1.5, via Cloudflare for SaaS).

## Services / Support

- **`TenantContext`** — Request-scoped singleton holding the resolved tenant (`hasTenant()`, `tenant()`, `tenantOrFail()`). Set by `ResolveTenant`.
- **`TenantResolver`** — Maps a request to a tenant: (1) `X-Tenant` header override (dev/tooling only, when `tenancy.allow_header_override`), (2) exact host match in `tenant_domains`, (3) `<label>.<base_domain>` subdomain → slug, else unresolved. Aggressively cached with negative caching for unknown hosts.
- **`LandingResolver`** — Resolves the teacher's stored landing config into the fully rendered public payload: normalizes `layout` **and each section's `variant`** (defaulting a variant-less section to its type default), emits **per-locale** section content (all enabled locales, missing ones filled from the primary), resolves dynamic `courses`/`testimonials` sections to real `items`, derives the anchor `nav` (per-locale labels), and overlays each student's `enrolled` flag via `applyEnrollment()`.
- **`LandingSchema`** (Support) — The v2 landing contract: page layout list, section-type catalog, the **per-type layout-variant catalog** (`VARIANTS`, with `variantsFor()`/`variantOrDefault()` helpers), per-type content/config validation rules, locale helpers (`supportedLocales()`, `normalizeLocales()`), per-locale `sanitize()` for saving (with unique-key dedup + per-section variant resolution), and the `defaults()` seed. See below for the section-type + variant tables.
- **`EntityVersion`** (Support) — Optimistic-concurrency helper for the editor endpoints: derives an `ETag` from a model's identity + `updated_at`, and enforces an optional `If-Match` precondition on writes (`412` on mismatch) so two editors can't silently overwrite the shared `teacher_profiles` row.
- **`EnsureRegisteredDomain`** + **`ResolveTenant`** (Middleware) — the `tenant` middleware group: the first hard-gates unregistered/inactive hosts (404/403), the second resolves + binds the tenant and RLS session.

### Landing section types (`LandingSchema::TYPES`)

`hero`, `stats`, `features`, `about`, `steps`, `courses`, `testimonials`, `packages`, `cta`, `contact`. Page layout (`layout`): `classic` (default), `grid`, `spotlight`.

- **Dynamic** (`courses`, `testimonials`): the teacher stores a `config` block; the public endpoint resolves it into `items`.
- **Item-authored** (`stats`, `features`, `steps`): the teacher edits `content.items` directly. Each item is whitelisted to the type's shape on save (unknown keys dropped): `stats` → `{value, label}`; `features` → `{icon?, title, desc?}`; `steps` → `{n?, title, desc?}`. `features`/`steps` also edit `title`/`subtitle`.
- **Item-preserved** (`packages`): only `title`/`subtitle` are editable; `content.items` (a nested billing shape) are carried over from the last save.

### Per-section layout variants (`LandingSchema::VARIANTS`)

Every section has a `variant` field — a per-type layout the teacher chooses from the editor. Each type offers exactly **4 variants**; the **first is the default** (applied when a section is stored without a variant, e.g. a pre-variant row). A variant is only valid for its own type — the `PUT` validates `sections.*.variant` against the set for that section's `type` and rejects a cross-type value with `422`.

| Section type | Variant 1 (default) | Variant 2 | Variant 3 | Variant 4 |
|---|---|---|---|---|
| `hero` | `split` | `centered` | `image_bg` | `minimal` |
| `stats` | `bar` | `grid` | `cards` | `inline` |
| `features` | `grid` | `list` | `cards` | `icons_left` |
| `about` | `image_right` | `image_left` | `stacked` | `text_only` |
| `steps` | `horizontal` | `vertical` | `numbered_cards` | `timeline` |
| `courses` | `grid` | `carousel` | `list` | `spotlight` |
| `testimonials` | `cards` | `slider` | `quote_wall` | `single_featured` |
| `packages` | `columns` | `table` | `cards` | `stacked` |
| `cta` | `banner` | `split` | `boxed` | `minimal` |
| `contact` | `form_right` | `form_left` | `stacked` | `info_only` |

The `variant` is a **presentation** hint: the SPA maps `(type, variant)` to a renderer component. It is orthogonal to `content`/`config` — switching a section's variant never changes its data, only how the frontend lays it out. It is **not** per-locale (one layout serves every language).

### Localization (per-locale content)

- **Supported vs enabled.** The platform supports a fixed set of UI languages (`tenancy.supported_locales`, default `ar,en`). A teacher **enables** a subset for their academy (`teacher_profiles.locales`) and marks one as `primary_locale` (the fallback; defaults to `tenancy.default_locale`, `ar`). Enabling/disabling a language is part of saving the landing — see `PUT /teacher/landing`.
- **Content shape.** Every section's `content` is a **map keyed by locale**: `content: { "ar": { …type fields… }, "en": { …type fields… } }`. Only `content` is translated — a dynamic section's `config` (data selection) and its resolved `items` are **not** per-locale (course titles/reviews render as authored in their own records).
- **Fallback.** On the public payload, any locale missing from a section is filled from the `primary_locale`, so the page never renders blank when a translation is incomplete.
- **Removing a language** drops that locale's content from all sections on the next save (non-served locales are not retained).
- **Config is shared:** `config` (courses/testimonials) stays at the section level, identical across languages.

### Adding / duplicating sections

The teacher may add, remove, reorder, and **duplicate** sections — restricted to the catalog types above (no invented types). Section `key`s are made **unique on save** (a duplicate `about` becomes `about-2`, `about-3`, …) so anchor `nav` targets (`#<key>`) stay unambiguous.

---

## Endpoints

### `GET /tenant/context`

**Purpose:** Resolve the current host to a tenant and return its identity, status, branding/theme, locale, and enabled feature flags — the payload the SPA loads on boot. Landing content is served separately by `GET /tenant/landing`.

**Auth:** 🔓 Public (tenant middleware only)
**Middleware:** `tenant`, `throttle:public` (per-IP rate limit — see conventions)

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

Notes: `branding` fields are `null` until the teacher sets them; `socials` is an empty object `{}` when unset. `status` is one of `active`, `suspended`, `under_review`, `expired`. `features` is currently always `[]` (per-tenant flags TODO). `locale.default` is the tenant's `primary_locale` and `locale.supported` is its **enabled** languages (primary first); a tenant that has enabled none falls back to `[<default_locale>]` (e.g. `["ar"]`), not the full platform set.

**Caching:** the response carries an `ETag` (derived from the tenant's identity/status + branding version) and `Cache-Control: public, max-age=<context_cache_ttl>` (default 60s). A conditional request whose `If-None-Match` equals the current `ETag` gets a bodyless **`304 Not Modified`**. `Vary: X-Tenant` guards a shared cache against the dev `X-Tenant` override.

| Response header | Example |
|---|---|
| ETag | `"9f2b…"` |
| Cache-Control | `public, max-age=60` |
| Vary | `X-Tenant` |

**Errors:**
- `304` — `If-None-Match` matched; no body (branding unchanged).
- `404 tenant_not_found` — the host resolved to no tenant (envelope: `{ "error": { "code": "tenant_not_found", "message": "لا يوجد حساب مرتبط بهذا العنوان." } }`).
- `429 too_many_requests` — per-IP rate limit exceeded (`throttle:public`).
- `404` — host is not a registered tenant domain (thrown earlier by `EnsureRegisteredDomain`).
- `403` — host maps to a non-active (suspended/expired) tenant (`EnsureRegisteredDomain`).

---

### `GET /tenant/landing`

**Purpose:** Return the fully resolved public landing page for the SPA: normalized `layout`, anchor `nav` links, and the ordered visible `sections` with dynamic `courses`/`testimonials` sections resolved into real `items`. Auth is **optional** — if a bearer token is present, each resolved course item carries an `enrolled` flag for that student.

**Auth:** 🔓 Public, optional auth (bearer token enriches `enrolled`)
**Middleware:** `tenant`, `throttle:public` (per-IP rate limit — see conventions)

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
    "locales": ["ar", "en"],
    "primary_locale": "ar",
    "nav": {
      "links": [
        { "label": { "ar": "من نحن", "en": "About" }, "target": "#about" },
        { "label": { "ar": "الكورسات", "en": "Courses" }, "target": "#courses" },
        { "label": { "ar": "آراء الطلاب", "en": "Testimonials" }, "target": "#testimonials" },
        { "label": { "ar": "تواصل معنا", "en": "Contact" }, "target": "#contact" }
      ]
    },
    "sections": [
      {
        "key": "hero",
        "type": "hero",
        "variant": "split",
        "visible": true,
        "order": 1,
        "content": {
          "ar": {
            "eyebrow": "أهلاً بك",
            "title_html": "أتقن <span>الفيزياء</span>",
            "description": "دروس مصمّمة لصفّك الدراسي.",
            "primary_cta": { "label": "ابدأ الآن" },
            "chips": [{ "text": "معتمد", "type": "green" }]
          },
          "en": {
            "eyebrow": "Welcome",
            "title_html": "Master <span>Physics</span>",
            "description": "Lessons tailored to your grade.",
            "primary_cta": { "label": "Start now" },
            "chips": [{ "text": "Certified", "type": "green" }]
          }
        }
      },
      {
        "key": "courses",
        "type": "courses",
        "variant": "grid",
        "visible": true,
        "order": 5,
        "content": {
          "ar": { "title": "الكورسات", "subtitle": "" },
          "en": { "title": "Courses", "subtitle": "" }
        },
        "items": [
          {
            "id": 41,
            "uuid": "3f1c9a2b-8d47-4e10-9b6a-1c2d3e4f5061",
            "slug": "physics-grade-3",
            "title": "فيزياء الثالث الثانوي",
            "cover_url": "https://cdn.elameed.app/covers/41.jpg",
            "thumbnail_url": "https://cdn.elameed.app/thumbs/41.jpg",
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
        "variant": "cards",
        "visible": true,
        "order": 7,
        "content": {
          "ar": { "title": "آراء الطلاب", "subtitle": "" },
          "en": { "title": "Testimonials", "subtitle": "" }
        },
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
- Every section carries a `variant` — its per-type layout (see the variant table above). It is normalized to one of that type's 4 variants and falls back to the type default when unset. The SPA selects a renderer from `(type, variant)`.
- **Localized:** `locales` (primary first) + `primary_locale` describe the languages present; every section's `content` is a per-locale map covering **all** `locales` (missing translations filled from `primary_locale`). `nav.links[].label` is likewise a per-locale map. The SPA renders the active language and switches client-side with no refetch.
- `nav.links` are derived from visible, nav-worthy section types (`about`, `features`, `courses`, `steps`, `testimonials`, `packages`, `contact`); `target` is `#<section key>`, each locale's `label` falls back to a capitalized type name when that section has no `content.<locale>.title`.
- Only `courses` and `testimonials` sections carry an `items` array; static sections do not. `items` are **not** per-locale (course/review data renders as authored).
- Course card image `cover_url` uses a **fallback chain** so a card is never imageless when any image exists: `course.cover_url` → `course.thumbnail_url` → the first published lesson's video poster (`media_assets.thumbnail_url`). `thumbnail_url` in the item stays the course's own value (may be `null`). Set the course's `cover_url` (or `thumbnail_url`) to control the card image directly.
- `enrolled` is `true` only for the authenticated student's active enrollments; anonymous requests always get `false`.
- `courses` items are always **published** courses — including `source=selected`: a hand-picked course that is later unpublished/archived drops out of the public landing automatically.
- Prices are integer minor units + `currency`; timestamps are ISO-8601 UTC.

**Caching:** this is a public hot path, so the **viewer-agnostic** payload is cached server-side per tenant (`landing_cache_ttl`, default 60s). The cache key carries the profile's `updated_at`, so a landing/branding edit is reflected immediately (new key); course/review changes surface within the TTL. The per-student `enrolled` flags are overlaid **after** the cache read, so cached data is never user-specific (anonymous and authenticated requests share the same base payload).

**Errors:**
- `404` / `403` — same host-gate errors as `GET /tenant/context` (`EnsureRegisteredDomain`). A required-but-missing tenant surfaces as a server error (`tenantOrFail`).
- `429 too_many_requests` — per-IP rate limit exceeded (`throttle:public`).

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

Notes: unset `contact` / `socials` serialize as empty objects `{}`; the other fields are `null` until set. The response carries an **`ETag`** (the profile's version) — capture it and echo it as `If-Match` on `PUT` to guard against overwriting a concurrent edit.

**Errors:**
- `401` — missing/invalid bearer token.
- `403` — authenticated user is not an active `teacher` member of this tenant.

---

### `PUT /teacher/profile`

**Purpose:** Upsert the current tenant's branding profile (FR-M02-03). Always responds `200` (upsert, never `201`).

> **Partial-merge semantics:** omitted top-level keys are left unchanged (send an explicit `null` to clear one). Nested objects `contact`/`socials` are **replaced wholesale**, not deep-merged — send the full object you want to keep.

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

**Optimistic concurrency (optional):** send `If-Match: <etag>` (the `ETag` from a prior GET/PUT). If the row changed since, the write is rejected with **`412 precondition_failed`** so you can reload and retry instead of clobbering the other edit. Omit the header to skip the check (backward compatible). The response echoes the new `ETag`.

**Response 200:** Same shape as `GET /teacher/profile` (the updated `TeacherProfileResource`), plus an `ETag` header.

**Errors:**
- `422` — validation failure (e.g. bad hex color, invalid URL/email). Error envelope with `details` per field.
- `412 precondition_failed` — `If-Match` sent but the profile was modified since it was read.
- `401` / `403` — as above.

---

### `GET /teacher/landing`

**Purpose:** Return the teacher's **editable** landing state (LANDING_CONTRACT_V2 authoring shape): the enabled `locales` + `primary_locale`, `layout`, derived `nav`, and **all** sections (including hidden) with their **per-locale** `content` and — for dynamic sections — the raw `config` (NOT resolved `items`), so the editor renders controls, not preview data. Falls back to `LandingSchema::defaults()` when nothing is saved yet.

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
    "locales": ["ar", "en"],
    "primary_locale": "ar",
    "nav": {
      "links": [
        { "label": { "ar": "الكورسات", "en": "Courses" }, "target": "#courses" },
        { "label": { "ar": "آراء الطلاب", "en": "Testimonials" }, "target": "#testimonials" },
        { "label": { "ar": "تواصل معنا", "en": "Contact" }, "target": "#contact" }
      ]
    },
    "sections": [
      {
        "key": "hero",
        "type": "hero",
        "variant": "split",
        "visible": true,
        "order": 1,
        "content": {
          "ar": {
            "eyebrow": "أهلاً بك",
            "title_html": "أتقن <span>الفيزياء</span>",
            "primary_cta": { "label": "ابدأ الآن" },
            "teacher": { "name": "", "role": "", "image_url": null, "card_stats": [] },
            "chips": []
          },
          "en": {
            "eyebrow": "",
            "title_html": "",
            "primary_cta": { "label": "Start now" },
            "teacher": { "name": "", "role": "", "image_url": null, "card_stats": [] },
            "chips": []
          }
        }
      },
      {
        "key": "courses",
        "type": "courses",
        "variant": "grid",
        "visible": true,
        "order": 5,
        "content": {
          "ar": { "title": "الكورسات", "subtitle": "" },
          "en": { "title": "Courses", "subtitle": "" }
        },
        "config": { "source": "featured", "category_id": null, "course_ids": [], "limit": 6 }
      }
    ]
  }
}
```

Notes: unlike `GET /tenant/landing`, dynamic sections here expose `config` and NOT resolved `items`. Each section includes its `variant` (the type default when never set) so the editor can preselect the layout control. Sections are returned as stored (all keys, including `visible: false`), with `content` keyed **per enabled locale**. The response carries an **`ETag`** (the profile's version) — echo it as `If-Match` on `PUT` for optimistic concurrency.

**Errors:** `401` / `403` — as above.

---

### `PUT /teacher/landing`

**Purpose:** Author the landing page (FR-M02-04): set the enabled `locales` + `primary_locale`, choose a `layout`, and submit the full ordered list of typed sections with **per-locale** content. The server **sanitizes** input (keeps known types/fields only, per enabled locale), makes section `key`s unique, preserves non-editable `items` from the previous save for item-preserved types (per locale), cleans dynamic `config`, and sanitizes `hero.title_html` (only bare `<span>` allowed). Content for locales not in `locales` is dropped. Always responds `200`.

Omitting `locales`/`primary_locale` keeps the academy's current language set. Section types are restricted to the catalog — the teacher may add/duplicate instances, not invent types.

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
  "locales": ["en", "ar"],
  "primary_locale": "en",
  "sections": [
    {
      "key": "hero",
      "type": "hero",
      "variant": "centered",
      "visible": true,
      "order": 1,
      "content": {
        "en": {
          "eyebrow": "Welcome",
          "title_html": "Master <span>Physics</span>",
          "description": "Top-rated lessons for your grade.",
          "primary_cta": { "label": "Start now" },
          "chips": [{ "text": "Certified", "type": "green" }]
        },
        "ar": {
          "eyebrow": "أهلاً بك",
          "title_html": "أتقن <span>الفيزياء</span>",
          "description": "دروس مصمّمة لصفّك الدراسي.",
          "primary_cta": { "label": "ابدأ الآن" },
          "chips": [{ "text": "معتمد", "type": "green" }]
        }
      }
    },
    {
      "key": "courses",
      "type": "courses",
      "variant": "carousel",
      "visible": true,
      "order": 3,
      "content": {
        "en": { "title": "My Courses", "subtitle": "Pick a track" },
        "ar": { "title": "كورساتي", "subtitle": "اختر مسارك" }
      },
      "config": { "source": "featured", "category_id": null, "course_ids": [], "limit": 6 }
    }
  ]
}
```

**Top-level fields**

| Field | Type | Required | Rules |
|---|---|---|---|
| `layout` | string | no (`sometimes`) | one of `classic`, `grid`, `spotlight` |
| `locales` | array | no (`sometimes`) | ≥1; each a platform-supported locale (`ar`, `en`) |
| `locales.*` | string | — | one of the supported locales |
| `primary_locale` | string | no (`sometimes`) | a supported locale; **must be within `locales`** |
| `sections` | array | **yes** | max 30 items |
| `sections.*.key` | string | **yes** | max 40 (deduped on save → `-2`, `-3`, …) |
| `sections.*.type` | string | **yes** | one of `hero`, `stats`, `features`, `about`, `steps`, `courses`, `testimonials`, `packages`, `cta`, `contact` |
| `sections.*.variant` | string | no (`sometimes`) | nullable; one of the 4 layouts for this section's `type` (see the per-section variant table); defaults to the type default when omitted |
| `sections.*.visible` | boolean | **yes** | — |
| `sections.*.order` | integer | no | nullable, min 1 (defaults to array position) |
| `sections.*.content` | object | no (`sometimes`) | **per-locale map**: `{ <locale>: {…} }` |
| `sections.*.content.<locale>` | object | no (`sometimes`) | that locale's content, validated per type (see below) |

**Per-type editable `content` fields** (the fields below are validated **within each enabled locale**, e.g. `sections.*.content.ar.title`)

| Type | Editable content fields |
|---|---|
| `hero` | `eyebrow`, `title_html` (bare `<span>` only), `description`, `note`, `primary_cta.label`, `secondary_cta.label`, `teacher.{name,role,image_url,card_stats[].{value,label}}`, `chips[].{text,type∈green\|red\|plain}` |
| `about` | `badge`, `title`, `body`, `image_url`, `points[]` |
| `cta` | `title`, `subtitle`, `cta.label` |
| `courses`, `testimonials`, `packages`, `contact` | `title`, `subtitle` only |
| `stats` | `items[].{value, label}` |
| `features` | `title`, `subtitle`, `items[].{icon?, title, desc?}` |
| `steps` | `title`, `subtitle`, `items[].{n?, title, desc?}` |

**Dynamic-section `config` fields**

| Type | Config fields |
|---|---|
| `courses` | `config.source` ∈ `featured\|all\|category\|selected` (required); `config.category_id` (int, nullable); `config.course_ids[]` (int, max 24); `config.limit` (1–24, default 6) |
| `testimonials` | `config.source` ∈ `latest\|top_rated` (required); `config.min_rating` (0–5); `config.limit` (1–24, default 6) |

Cross-field validation: `primary_locale` (when both are sent) must be one of `locales`; for a `courses` section with `source=category`, `category_id` must be a category in this academy; with `source=selected`, all `course_ids` must belong to this teacher (otherwise `422`).

**Optimistic concurrency (optional):** send `If-Match: <etag>` (from a prior GET/PUT). If the row changed since — note both landing **and** branding save this same row, so either edit bumps the version — the write is rejected with **`412 precondition_failed`**. Omit the header to skip the check. The response echoes the new `ETag`.

**Response 200:** Same shape as `GET /teacher/landing` (the freshly saved `TeacherLandingResource`), plus an `ETag` header.

**Errors:**
- `422` — validation failure (unknown type, too many sections, invalid config, category/courses not owned, etc.).
- `412 precondition_failed` — `If-Match` sent but the landing/profile row was modified since it was read.
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
| `file` | file | **yes** | raster image MIME: `image/jpeg`, `image/png`, `image/webp`, `image/gif`; max 5120 KB (5 MB) |

> **SVG is not accepted.** An SVG can embed `<script>`, and uploads are served from the public disk on the academy's own origin, so accepting SVG would be a stored-XSS vector. Only raster formats are allowed.

**Response 200**

```json
{
  "data": {
    "url": "https://cdn.elameed.app/storage/landing/12/9aX7bQ...png"
  }
}
```

**Errors:**
- `422` — missing file, non-raster/unsupported MIME type (incl. SVG), or over 5 MB.
- `401` / `403` — as above.

---

## Tenancy & error conventions (applies to every endpoint)

- **Base path:** all routes are under `/api/v1`.
- **Host gate:** every route runs through the `tenant` middleware group — `EnsureRegisteredDomain` (rejects unknown host → `404`, inactive tenant → `403`) then `ResolveTenant` (binds the tenant + RLS session). The tenant is resolved from the **Host** header (custom domain or `*.elameed.app` subdomain). An `X-Tenant: <slug>` header overrides only when `tenancy.allow_header_override` is enabled (local/tooling).
- **Success envelope:** `{ "data": ... }`.
- **Error envelope:** `{ "error": { "code": "...", "message": "...", "details": { } } }`.
- **Money:** integer minor units + `currency`. **Timestamps:** ISO-8601 UTC. **Arabic content:** UTF-8 as-is.
- **Auth:** Laravel Sanctum, `Authorization: Bearer <token>`. `role:teacher` routes additionally require `auth:sanctum` + `active` (active tenant membership; a suspended member is blocked here).
- **Rate limiting (public endpoints):** `GET /tenant/context` and `GET /tenant/landing` run through `throttle:public` — a per-IP limit (`tenancy.public_rate_limit`, default 120/min). Exceeding it returns `429 too_many_requests`.
- **Caching (public endpoints):** `GET /tenant/context` is revalidated via `ETag`/`If-None-Match` (`304`) with `Cache-Control: public, max-age=<context_cache_ttl>`; `GET /tenant/landing` caches its viewer-agnostic payload server-side (`landing_cache_ttl`), keyed by the profile's `updated_at`, with per-student `enrolled` overlaid after the cache read. Tuning keys live in `config/tenancy.php`.
- **Optimistic concurrency (editor writes):** `GET /teacher/profile` and `GET /teacher/landing` return an `ETag`. On `PUT`, an optional `If-Match` echoes it back; if the (shared) `teacher_profiles` row changed since it was read, the write is rejected with `412 precondition_failed`. Omitting `If-Match` skips the check. The token is second-granular (from `updated_at`) — sufficient for a human editor, not for serializing sub-second machine writes.
