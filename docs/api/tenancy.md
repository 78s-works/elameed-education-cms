# Tenancy Module

> The Tenancy module is the platform's multi-tenant backbone. It maps an incoming **Host** (custom domain or `*.elameed.app` subdomain) to a tenant academy, binds that tenant for the rest of the request (RLS/`BelongsToTenant` scoping), and exposes the tenant's public identity, branding/theme, and teacher-authored landing page to the SPA. It also owns the teacher-facing endpoints for editing branding (profile) and the landing page (layout + typed sections), a media upload helper for landing images, per-academy switches for access (sign-in/registration) and **landing mode** (CMS sections vs. a frontend-bundled custom page), and a per-academy **site metadata** store (arbitrary key/value entries, namespaced by `group`) managed via `/teacher/meta` and surfaced to the public landing (branding + meta bundle) via `GET /tenant/landing/meta`. Landing content follows the **LANDING_CONTRACT_V2** contract: a fixed catalog of typed sections where two types (`courses`, `testimonials`) are resolved server-side into real items.
>
> **Per-section layout:** on top of the page-level `layout` (overall theme: `classic|grid|spotlight`), **every section carries its own `variant`** — one of **4 layouts defined per section type** (`LandingSchema::VARIANTS`). The teacher picks a section's variant from the editor, independently per section, so e.g. the `courses` section can render as a `carousel` while `testimonials` render as a `slider`. Variants are validated **per type** (a `courses` variant can't be set on a `hero`), and any section stored without a variant resolves to that type's default (the first variant listed).
>
> **Multi-language:** landing content is translatable. The teacher enables a set of `locales` (a subset of the platform-supported languages) and picks a `primary_locale`; each section's `content` is authored **per locale** (`{ "ar": {…}, "en": {…} }`). The public landing returns **all** enabled locales in one payload (the SPA switches client-side), with any untranslated section falling back to the primary locale. The teacher may also **add, remove, reorder, and duplicate** section instances — but only of the code-defined catalog types (no freeform/HTML sections).

## Models

- **`Tenant`** — A teacher academy; the **global** tenant-registry row (NOT tenant-scoped, no `BelongsToTenant`/RLS). Has `uuid`, `slug`, `name`, `status` (enum), soft-deletes, and relations to `domains`, `teacherProfile`, and `owner`.
- **`TenantDomain`** — Host → tenant mapping row. **Global** (read during resolution, before any tenant scope exists). Holds a public `uuid` (now `HasUuids`, route key `uuid`), `host`, `type` (subdomain|custom), `is_primary`, and Cloudflare-for-SaaS SSL fields (`cf_custom_hostname_id`, `ssl_status`, `verified_at`). Because it is global, the teacher domain API resolves `{domain}` **scoped-by-tenant in the controller**, not via implicit route-model binding.
- **`TeacherProfile`** — Per-tenant branding + landing configuration; one row per tenant and the **first** tenant-scoped model (`BelongsToTenant` filters every query and auto-fills `tenant_id`). Stores `logo_url`, `favicon_url` (browser-tab icon), `cover_url`, `primary_color`, `secondary_color`, `bio`, `contact` (json), `socials` (json), `layout`, `landing_sections` (json, per-locale content), `locales` (json list of enabled languages), `primary_locale` (string), `hide_ranking`, the access switches `login_enabled` / `registration_enabled` (both default `true`; see `GET/PUT /teacher/access`), and `custom_landing_enabled` (default `false`; the landing-mode switch — see `GET/PUT /teacher/custom-landing`).
- **`TeacherMeta`** — A single key/value metadata entry the teacher manages from the panel (SEO tags, custom head data, …); **many rows per tenant** in the `teacher_meta` table, tenant-scoped by `BelongsToTenant`. Columns: `group` (namespace, default `general`), `key`, `value` (nullable text), `sort_order` (default `0`). Unique per `(tenant_id, group, key)` — no duplicate key within a group. Powers the `/teacher/meta` CRUD endpoints; unrelated to the `teacher_profiles` row.

## Enums

- **`TenantStatus`** (`app/Modules/Tenancy/Enums`): `active`, `suspended`, `under_review`, `expired`. Only `active` is operational (`isOperational()`), i.e. permits teacher-side actions.
- **`TenantDomainType`** (`app/Modules/Tenancy/Enums`): `subdomain` (Phase 1), `custom` (Phase 1.5, via Cloudflare for SaaS).

## Services / Support

- **`TenantContext`** — Request-scoped singleton holding the resolved tenant (`hasTenant()`, `tenant()`, `tenantOrFail()`). Set by `ResolveTenant`.
- **`TenantResolver`** — Maps a request to a tenant: (1) `X-Tenant` header override (dev/tooling only, when `tenancy.allow_header_override`), (2) exact host match in `tenant_domains`, (3) `<label>.<base_domain>` subdomain → slug, else unresolved. Aggressively cached with negative caching for unknown hosts.
- **`LandingResolver`** — Resolves the teacher's stored landing config into the fully rendered public payload: normalizes `layout` **and each section's `variant`** (defaulting a variant-less section to its type default), emits **per-locale** section content (all enabled locales, missing ones filled from the primary), resolves dynamic `courses`/`testimonials` sections to real `items`, derives the anchor `nav` (per-locale labels), and overlays each student's `enrolled` flag via `applyEnrollment()`.
- **`LandingSchema`** (Support) — The v2 landing contract: page layout list, section-type catalog, the **per-type layout-variant catalog** (`VARIANTS`, with `variantsFor()`/`variantOrDefault()` helpers), per-type content/config validation rules, locale helpers (`supportedLocales()`, `normalizeLocales()`), per-locale `sanitize()` for saving (with unique-key dedup + per-section variant resolution), and the `defaults()` seed. See below for the section-type + variant tables.
- **`EntityVersion`** (Support) — Optimistic-concurrency helper for the editor endpoints: derives an `ETag` from a model's identity + `updated_at`, and enforces an optional `If-Match` precondition on writes (`412` on mismatch) so two editors can't silently overwrite the shared `teacher_profiles` row.
- **`CustomDomainService`** — Registers/lists/removes a tenant's custom domains and switches the primary host (M02, custom domains Part 2). Normalizes + validates the host (rejects central/platform hosts, the base-domain apex, any `*.<base_domain>` subdomain, malformed hosts, and duplicates; honours `config('domains.custom_enabled')` and the `max_per_tenant` ceiling), writes the row with `ssl_status='pending'`, and builds the `dns` CNAME instruction (target = `config('domains.cname_target')`). `tenant_domains` is global, so every read/write is explicitly constrained to the tenant.
- **`EnsureRegisteredDomain`** + **`ResolveTenant`** (Middleware) — the `tenant` middleware group: the first hard-gates unregistered/inactive hosts (404/403), the second resolves + binds the tenant and RLS session. A registered **custom** host resolves exactly like a subdomain (the resolver/gate are type-agnostic).
- **`DynamicTenantCors`** (Middleware, **prepended** before Laravel's `HandleCors`, not an alias) — reflects a request `Origin` into `cors.allowed_origins` when its host is a registered, **active** tenant host (subdomain OR custom domain) or a central/dev host, replacing the static localhost-only list. Validated against the same `tenant_domains` source of truth as routing, so CORS can never trust a host that wouldn't resolve to a tenant.

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
      "favicon_url": "https://cdn.elameed.app/landing/12/favicon.ico",
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
    "auth": {
      "login_enabled": true,
      "registration_enabled": true
    },
    "landing": {
      "custom_enabled": false
    },
    "features": []
  }
}
```

Notes: `branding` fields are `null` until the teacher sets them; `socials` is an empty object `{}` when unset. `branding.favicon_url` is the academy's browser-tab icon — the SPA sets it as `<link rel="icon">` on boot (falling back to the platform default when `null`). `status` is one of `active`, `suspended`, `under_review`, `expired`. `features` is currently always `[]` (per-tenant flags TODO). `locale.default` is the tenant's `primary_locale` and `locale.supported` is its **enabled** languages (primary first); a tenant that has enabled none falls back to `[<default_locale>]` (e.g. `["ar"]`), not the full platform set. `auth` mirrors the teacher's per-academy access switches (`PUT /teacher/access`): the SPA hides the sign-in / sign-up forms when a flag is `false`, and the API enforces the same at `POST /auth/login` and `POST /auth/register`. Both default to `true`. `landing.custom_enabled` is the landing-mode switch (`PUT /teacher/custom-landing`, default `false`): when `true` the SPA renders **its own bundled `custom/<slug>/` page** (the folder keyed by this tenant's `data.slug`) instead of fetching `GET /tenant/landing`; when `false` it loads the CMS-built landing sections as usual.

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

### `GET /tenant/landing/meta`

**Purpose:** Return everything the SPA needs to paint the landing's `<head>` and branding shell in **one** public call: the tenant's identity (`site`), `branding`/theme, and the teacher-managed key/value **site metadata** (`meta`, e.g. SEO/OG tags) grouped by `group`. This is the only public surface for the `teacher_meta` store — `GET /teacher/meta` is the teacher-only editor CRUD. Content sections are served separately by `GET /tenant/landing`; identity/auth/feature flags by `GET /tenant/context`.

**Auth:** 🔓 Public (tenant middleware only)
**Middleware:** `tenant`, `throttle:public` (per-IP rate limit — see conventions)

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| If-None-Match | optional (conditional GET → `304`) | `"9f2b…"` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "site": { "slug": "mrkhaled", "name": "أكاديمية مستر خالد" },
    "branding": {
      "logo_url": "https://cdn.elameed.app/landing/12/logo.png",
      "favicon_url": "https://cdn.elameed.app/landing/12/favicon.ico",
      "cover_url": "https://cdn.elameed.app/landing/12/cover.jpg",
      "primary_color": "#1E88E5",
      "secondary_color": "#FFB300",
      "bio": "مدرّس فيزياء بخبرة 10 سنوات.",
      "socials": { "facebook": "https://facebook.com/mrkhaled" }
    },
    "meta": {
      "seo": [
        { "key": "description", "value": "أفضل شرح فيزياء." },
        { "key": "keywords", "value": "فيزياء, ثانوية عامة" }
      ],
      "og": [
        { "key": "og:image", "value": "https://cdn.elameed.app/landing/12/og.jpg" }
      ]
    }
  }
}
```

Notes: `meta` is an object keyed by the entry's `group` (`seo`, `og`, `general`, …); each group's array is ordered by `sort_order` then `key`. `meta` serializes as an empty object `{}` when the teacher has none. `branding` fields are `null` until set and `socials` is `{}` when unset (same as `GET /tenant/context`). **Not** included here (by design): the teacher-only `contact` details (PII — see `GET /teacher/profile`), the `auth`/`features`/`status` flags, and `locale` (those live in `GET /tenant/context`).

**Caching:** carries an `ETag` + `Cache-Control: public, max-age=<context_cache_ttl>` (default 60s) exactly like `GET /tenant/context`. The `ETag` folds in **both** the branding version (`teacher_profiles.updated_at`) **and** the metadata version (entry count + latest `updated_at`), so any branding or meta change — including a **delete** — mints a new `ETag`. A conditional request whose `If-None-Match` matches gets a bodyless **`304 Not Modified`**. `Vary: X-Tenant` guards a shared cache against the dev override.

| Response header | Example |
|---|---|
| ETag | `"9f2b…"` |
| Cache-Control | `public, max-age=60` |
| Vary | `X-Tenant` |

**Errors:**
- `304` — `If-None-Match` matched; no body.
- `404 tenant_not_found` — the host resolved to no tenant.
- `404` / `403` — unregistered / non-active host (thrown earlier by `EnsureRegisteredDomain`).
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
    "favicon_url": "https://cdn.elameed.app/landing/12/favicon.ico",
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
  "favicon_url": "https://cdn.example.com/favicon.ico",
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
| `favicon_url` | string | no | nullable, valid URL, max 2048 (browser-tab icon) |
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

### `GET /teacher/access`

**Purpose:** Read the academy's access switches — whether students can sign in and whether new students can self-register. Stored on the tenant's `teacher_profiles` row; both default to `true`.

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Response 200**

```json
{ "data": { "login_enabled": true, "registration_enabled": true } }
```

---

### `PUT /teacher/access`

**Purpose:** Open or close sign-in and/or self-registration for the academy. Either flag may be sent alone (partial update). The change is enforced immediately at `POST /auth/login` and `POST /auth/register` (M11), and mirrored in `GET /tenant/context` → `data.auth` so the SPA can hide the forms.

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request body**

| Field | Type | Required | Notes |
|---|---|---|---|
| `login_enabled` | boolean | no | `false` blocks sign-in for **everyone except the teacher** (assistants, students, parents included). Only the teacher can still sign in — to reach their panel and re-open access. |
| `registration_enabled` | boolean | no | `false` rejects new student self-registration with `403`. |

**Response 200:** Same shape as `GET /teacher/access`.

**Errors:**
- `422` — a flag was sent as a non-boolean.
- `401` / `403` — not authenticated / not a teacher of this academy.

> **Effect on auth (M11).** With `login_enabled=false`, `POST /auth/login` returns `403 forbidden` for everyone except the teacher (assistants/students/parents) after their credentials verify — only the teacher still gets a token. With `registration_enabled=false`, `POST /auth/register` returns `403 forbidden` and creates nothing. An OTP already issued to a mid-flight registration can still complete — closing registration only stops **new** sign-ups from starting.

---

### `GET /teacher/custom-landing`

**Purpose:** Read the academy's **landing-mode** switch — whether the SPA renders its own bundled custom page or the CMS-built landing sections. Stored on the tenant's `teacher_profiles` row; defaults to `false` (CMS sections).

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Response 200**

```json
{ "data": { "custom_landing_enabled": false } }
```

---

### `PUT /teacher/custom-landing`

**Purpose:** Turn the custom landing on or off for the academy. When **on**, the SPA renders its own bundled `custom/<slug>/` page (the folder keyed by the tenant's `slug`, from `GET /tenant/context` → `data.slug`) and does **not** call `GET /tenant/landing`; when **off** (the default), it loads the CMS-built landing sections as usual. The flag is mirrored in `GET /tenant/context` → `data.landing.custom_enabled` so the SPA can pick the mode on boot. Always responds `200`.

> **Backend vs. frontend split.** The custom page itself lives in the **frontend** bundle (`custom/<slug>/`); the backend does not store or serve its markup. This endpoint only persists the boolean and surfaces it (with the tenant `slug`) — turning it on for a slug that has no bundled page is a frontend concern, not a backend error.

**Auth:** 🔒 `role:teacher`
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request body**

| Field | Type | Required | Notes |
|---|---|---|---|
| `custom_landing_enabled` | boolean | **yes** | `true` = render the bundled `custom/<slug>/` page; `false` = render the CMS landing sections. |

**Response 200:** Same shape as `GET /teacher/custom-landing`.

**Errors:**
- `422` — `custom_landing_enabled` missing or non-boolean (`error.code: validation_error`).
- `401` / `403` — not authenticated / not a teacher of this academy.

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

### Site metadata (`/teacher/meta`)

Teacher-managed **key/value metadata** for the academy, stored in the tenant-scoped `teacher_meta` table — one row per `(group, key)`. Each entry has a `group` (a free namespace, e.g. `seo`, `og`, `general`; defaults to `general`), a `key`, an optional `value`, and a `sort_order`. This is a **separate** store from the `teacher_profiles` branding/landing config: use it for SEO meta tags, Open-Graph tags, custom `<head>` data, or any ad-hoc site metadata. The SPA decides how to render the entries (e.g. emit `<meta name="{key}" content="{value}">` for the `seo` group).

All five routes are tenant-scoped and require `role:teacher`. `{meta}` binds by `id` through the `BelongsToTenant` global scope, so a teacher can only ever address their own rows — an id from another tenant resolves to **`404`**.

**Common auth/middleware (all five):** 🔒 `auth:sanctum` + `active` + `role:teacher`; middleware `tenant`, `auth:sanctum`, `active`, `role:teacher`. Standard headers apply (`Host` + optional dev `X-Tenant`, `Authorization: Bearer …`, `Accept: application/json`; writes also send `Content-Type: application/json`).

**Resource shape** (returned by every non-delete endpoint, wrapped in `{ "data": … }` / `{ "data": [ … ] }`):

```json
{ "id": 7, "group": "seo", "key": "description", "value": "Best physics academy", "sort_order": 1 }
```

#### `GET /teacher/meta`

**Purpose:** List the academy's metadata entries, ordered by `group`, then `sort_order`, then `key`.

**Query params**

| Param | Type | Required | Notes |
|---|---|---|---|
| `group` | string | no | Filter to a single namespace (e.g. `?group=seo`). Omit to return all groups. |

**Response 200:** `{ "data": [ <TeacherMetaResource>, … ] }` (empty array when none).

**Errors:** `401` / `403` — not authenticated / not a teacher of this academy.

#### `POST /teacher/meta`

**Purpose:** Create a metadata entry.

**Request body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `key` | string | **yes** | max 191; chars `A–Z a–z 0–9 _ . : -`; unique within `(group)` for this tenant |
| `group` | string | no | max 64; chars `A–Z a–z 0–9 _ . -`; **defaults to `general`** when omitted/empty |
| `value` | string | no | nullable; max 65535 |
| `sort_order` | integer | no | nullable; min 0 (defaults to `0`) |

**Response 201:** `{ "data": <TeacherMetaResource> }`.

**Errors:**
- `422` — validation failure (missing `key`, bad chars, or a duplicate `key` in the same `group` → `error.details.key`, message *"A meta entry with this key already exists in this group."*).
- `401` / `403` — as above.

#### `GET /teacher/meta/{meta}`

**Purpose:** Fetch a single entry by id.

**Response 200:** `{ "data": <TeacherMetaResource> }`.

**Errors:** `404` — no such entry for this tenant. `401` / `403` — as above.

#### `PUT /teacher/meta/{meta}`

**Purpose:** Update an entry. Same body and rules as `POST` (the unique-`key` check ignores the row being edited, so re-saving with an unchanged `key` is fine).

**Response 200:** `{ "data": <TeacherMetaResource> }`.

**Errors:**
- `422` — validation failure (incl. a `key` that collides with a **different** entry in the same group).
- `404` — no such entry for this tenant.
- `401` / `403` — as above.

#### `DELETE /teacher/meta/{meta}`

**Purpose:** Delete an entry.

**Response 204:** No content.

**Errors:** `404` — no such entry for this tenant. `401` / `403` — as above.

---

### Custom domains (`/teacher/domains`)

Attach the academy's own domain (M02, custom domains Part 2). A teacher points a CNAME at the platform's shared origin (`config('domains.cname_target')`, e.g. `connect.elameed.app`); once DNS propagates the host resolves to this tenant like any subdomain, and CORS trusts it (via `DynamicTenantCors`). TLS + ownership verification are handled by **Cloudflare-for-SaaS** in production — that provisioning is the documented future seam (`ssl_status` starts `pending`); the API records the row and returns the DNS record to publish.

`tenant_domains` is a **global** model, so `{domain}` (a `uuid`) is resolved **scoped-by-tenant inside the controller** (not implicit binding) — a uuid from another tenant → `404`. The auto-provisioned platform subdomain is read-only here (it can't be deleted). Config lives in `config/domains.php` (`custom_enabled`, `cname_target`, `max_per_tenant`, default 5).

**Common auth/middleware (all four):** 🔒 `auth:sanctum` + `active` + `role:teacher`; middleware `tenant`, `auth:sanctum`, `active`, `role:teacher`.

**Resource shape** (`TenantDomainResource`):
```json
{
  "uuid": "5f2c…-domain-uuid",
  "host": "academy.example.com",
  "type": "custom",
  "is_primary": false,
  "ssl_status": "pending",
  "verified_at": null,
  "created_at": "2026-07-27T10:00:00+00:00"
}
```

#### `GET /teacher/domains`
**Purpose:** List the academy's domains (primary first, then oldest), including the auto-provisioned platform subdomain.
**Response 200:** `{ "data": [ <TenantDomainResource>, … ] }` (not paginated).
**Errors:** `401` / `403`.

#### `POST /teacher/domains`
**Purpose:** Register a custom domain for the academy.

**Request body**
| Field | Type | Required | Rules |
|---|---|---|---|
| `host` | string | **yes** | max 253; a valid domain. Rejected: central/platform hosts, the base-domain apex, any `*.<base_domain>` subdomain, malformed hosts, and duplicates. |
| `is_primary` | boolean | no | when `true`, the new domain becomes the primary host (demotes the others) |

**Response 201:** the created `TenantDomainResource` **plus** a `dns` instruction:
```json
{
  "data": {
    "uuid": "5f2c…-domain-uuid",
    "host": "academy.example.com",
    "type": "custom",
    "is_primary": false,
    "ssl_status": "pending",
    "verified_at": null,
    "created_at": "2026-07-27T10:00:00+00:00",
    "dns": {
      "type": "CNAME",
      "name": "academy.example.com",
      "value": "connect.elameed.app",
      "note": "Add this record at your DNS provider. Verification and SSL are issued automatically once it propagates."
    }
  }
}
```
Audit-logged (`domain.registered`).

**Errors:**
- `422` — `host`: invalid format, a platform-managed host (central/apex/`*.<base_domain>`), an already-registered host, custom domains disabled (`custom_enabled=false`), or the `max_per_tenant` ceiling reached.
- `401` / `403`.

#### `POST /teacher/domains/{domain}/primary`
**Purpose:** Promote a domain to the academy's primary host (demotes the others).
**Response 200:** the refreshed `TenantDomainResource`.
**Errors:** `404` unknown/other-tenant uuid; `401` / `403`.

#### `DELETE /teacher/domains/{domain}`
**Purpose:** Remove a custom domain. The auto-provisioned **platform subdomain cannot be removed**.
**Response 204:** No content. Audit-logged (`domain.removed`).
**Errors:** `422` — attempting to delete the platform subdomain (`The platform subdomain cannot be removed.`); `404` unknown/other-tenant uuid; `401` / `403`.

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
