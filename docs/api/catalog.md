# Catalog Module

The Catalog module (`app/Modules/Catalog`, M04) owns the tenant's content library. After the VD change
set (`docs (1)/12` §7, `13`) the authoring model is:

```
academic_year  ─┬─►  standalone lesson  ─►  part (lesson_section)
                └─►  content package (recursive)  ─►  { lesson | sub-package }
```

**`courses`** still exist as the **public storefront unit** (and the thing coupons, reviews, and center
activation-codes scope to), but **teacher course CRUD, units, and bundles were retired** (migration
`2026_08_04_000010_retire_units_bundles.php` dropped `units`, `bundles`, `bundle_items`,
`unit_dependencies`). Teachers now author **standalone lessons** (each with typed **parts**) and group
them into **recursive content packages**, all within an **academic year** context.

Every route runs inside the `tenant` middleware group, so the tenant is resolved from the request host
(or the `X-Tenant` dev override) and all queries are tenant-isolated by the `BelongsToTenant` global
scope. The lesson/part/package/package-type authoring routes additionally run under the
**`academic-year`** middleware (`X-Academic-Year: <year-uuid>`), which binds a `BelongsToAcademicYear`
scope — content from another year (or tenant) `404`s. Public listings expose only the resolved tenant's
**published** content; teachers see all of their own content regardless of visibility.

> **Buying a package or a lesson** grants access by writing `enrollments`. A **package** purchase **fans
> out** into one enrollment per lesson it contains (recursively through sub-packages, idempotent — B15 /
> LP-D2); a **standalone lesson** purchase grants that one lesson. `enrollments` is the single source of
> truth checked by playback, progress, and exams. Checkout is handled by
> [Commerce](commerce.md); this module is authoring + browse only.

## Conventions (apply to every endpoint)

- Base prefix `/api/v1`. Success responses are `{ "data": ... }`; paginated lists add a `"meta"`
  block (`current_page`, `last_page`, `per_page`, `total`, `from`, `to`). Errors are
  `{ "error": { "code", "message", "details" } }`.
- Money is an integer in minor units (`price_minor`) paired with a 3-letter `currency`.
- Timestamps are ISO-8601 UTC (e.g. `publish_at`). Arabic content is UTF-8.
- **Tenancy:** tenant resolved from the `Host` header (dev override: `X-Tenant: <slug>`). Enforced by
  the `tenant` middleware group (`EnsureRegisteredDomain` → `ResolveTenant`), which binds the tenant
  before route-model binding so a bound model can never cross tenants.
- **Academic-year context:** lessons, parts, content-packages, and package-types are addressed under
  the `academic-year` middleware — send `X-Academic-Year: <year-uuid>`. There is **no persistent
  "current year"**; the header is the per-request selector. Academic-years CRUD itself is *not* year-scoped.
- **Auth:** Sanctum bearer token. Public browse needs no token. Teacher routes require
  `auth:sanctum` + `active` (active tenant membership) + `role:teacher` (pass-override adds
  `permission:homework`).
- **Route binding keys:** courses bind by `slug` (public); academic-years, content-packages(`uuid` field
  present but bound by **id**), and package-types bind by `uuid`; **lessons, parts, and package items bind
  by `id`** (own tenant + active-year data — lessons have no uuid). Nested controllers assert parent
  ownership (`section.lesson_id === lesson.id`, `attachment.lesson_id === lesson.id`) and `404` on mismatch.

## Models

| Model | Table | One-liner |
|---|---|---|
| `AcademicYear` | `academic_years` | Top-level content container (`uuid`, `name`, `sort_order`). Everything a teacher authors belongs to one. Bound by `uuid`; the active year is chosen per-request via `X-Academic-Year`. |
| `CourseCategory` | `course_categories` | Teacher's taxonomy (name + grade/subject/level/section + sort_order). A course optionally belongs to one. |
| `Course` | `courses` | The **public storefront** product (`uuid`/`slug`, pricing, visibility, marketing copy, `access_mode`). Soft-deletes. Teacher CRUD retired — read-only picker + public browse only. |
| `Lesson` | `lessons` | A **standalone** unit of content in an academic year (`academic_year_id`). Sellable on its own (`price_minor`, `is_purchasable`), channel-scoped (`access_mode` `center\|online\|both`). Has a video source (`video_asset_id` and/or `youtube_url`, selected by `active_video_source`), many attachments, typed **parts**, and the time-box config `availability_days` / `max_extensions` / `extension_hours` / `self_reopen_limit`. `unit_id` / `course_id` are **dormant** columns (units retired). |
| `LessonSection` | `lesson_sections` | A **part** of a lesson (ordered). `type` = `video` / `homework` / `quiz`. A `video` part points at a `media_asset_id` or a `youtube_url`; `homework` / `quiz` parts are backed by an `Exam` row (`exam_id`) holding degree + grading. Carries `access_mode` (⊆ the lesson's), `is_required`, and the gate config `gate_rule` + `max_tries` (part-gating, B10 / LP-11..14). |
| `Package` | `packages` | A **recursive content package** in an academic year (`uuid`, `name`, `access_mode`, `price_minor`, `is_purchasable`, optional `package_type_id`). Sold as one product; buying it fans out into per-lesson enrollments. |
| `PackageItem` | `package_items` | One entry in a package — **either a `lesson` or a sub-`package`** (`item_type` + `item_id`, ordered by `sort_order`). Recursive; cycle/ceiling/same-year guarded by `PackageItemService`. |
| `PackageType` | `package_types` | **(B27)** A teacher-managed content-package category, scoped to tenant + academic year (`uuid`, `name`, `sort_order`, `description`). A package optionally links one; deleting a type nulls `packages.package_type_id` (packages survive). |
| `LessonAttachment` | *(stored as `media_assets`)* | Not a dedicated model — attachments are `MediaAsset` rows of type `pdf` / `file` / `link` linked by `lesson_id`. The lesson's single `hls_video` asset is **not** an attachment. |
| `ContentAccessOverride` | `content_access_overrides` | A staff manual grant letting one `user_id` open a locked target (`lesson_id`/`section_id`), bypassing gates. Active while `revoked_at` is null. |
| `LessonAccessWindow` | `lesson_access_windows` | A student's time-boxed access window for one lesson (`started_at`, `expires_at`, `locked_at`, `extensions_used`). Opens on first start/play; auto-locks at expiry. |
| `LessonExtensionRequest` | `lesson_extension_requests` | A student's post-expiry request for more time; staff grant/deny. A grant extends the window by the lesson's `extension_hours`. |
| `ContentDependency` | `content_dependencies` | **Dormant/legacy.** The model + `LessonSection::dependencies()` relation still exist, but there are **no authoring routes** and the new part gate uses `gate_rule` instead (see below). Not emitted by any resource. |

**Structure:** an `AcademicYear` **hasMany** `Lesson` and **hasMany** `Package`. A `Lesson` **hasMany**
attachments (MediaAsset, type != `hls_video`), **belongsTo** one `videoAsset`, and **hasMany** typed
`LessonSection` parts. A `Package` **hasMany** `PackageItem`, each pointing at a `Lesson` **or** another
`Package` (recursive).

### Key enums

- **`AccessMode`** (`App\Modules\Catalog\Enums`) — the delivery **channel** of a lesson / part / package /
  course: `center`, `online`, `both`. Ceiling/subset rule (`isVisibleTo`): a `both` parent admits any
  child and matches every student; a specific channel admits only itself. A part's `access_mode` must be
  ⊆ its lesson's; a package's must be ⊆ its items'. Supersedes the retired `is_center` flag (VD doc 12 R2).
- **`ContentVisibility`** — `visibility`: `visible` (publicly listed, subject to `publish_at`), `hidden`
  (not listed), `scheduled` (becomes visible at `publish_at`). "Published" = `visible` AND
  (`publish_at` null OR `publish_at <= now()`).
- **`VideoSource`** — `active_video_source`: `upload` (protected `hls_video`) or `youtube`. The inactive
  source stays stored but is never exposed to students. Activating `youtube` requires a valid `youtube_url`.
- **`LessonSectionType`** — part `type`. **Authoring** values: `video`, `homework`, `quiz`. (Legacy
  runtime values `lecture_video` / `pdf` / `quiz_solution` / `hw_solution` exist in the enum for older data
  but are not authorable.)
- **`SectionDelivery`** / **`GateRule`** / **`ExamGradingMode`** / **`ExamPassMode`** — the homework/quiz
  part config (delivery channel, unlock gate, auto/manual grading, pass rule). See [Assessment](assessment.md).
- **`MediaType`** / **`MediaStatus`** (`App\Modules\Media\Enums`) — attachment `type` (`pdf`/`file`/`link`,
  plus `hls_video` for the lesson video) and lifecycle (`ready`, or `uploading → transcoding → ready|failed`).

---

## Endpoints

53 endpoints: **2 public catalogue** + **51 teacher/student authoring & access** — 5 academic-years,
4 categories, 1 teacher course list (read-only), 5 lessons, 7 parts (5 CRUD + reorder-in-that-5, 2
pass-override), 8 content-packages (5 + 3 items), 5 package-types, 3 attachments, 3 lesson availability,
3 extension-requests, 5 student lesson content & access, 2 student library.

> **Retired surface (do not reintroduce).** There are **no** routes for `/teacher/courses` CRUD,
> `/teacher/units*`, `/bundles`, `/teacher/bundles`, or `…/dependencies`. Public package browse is
> `GET /courses?view=packages` (not `/bundles`). Teacher content packages live at
> `/teacher/content-packages` — **`/teacher/packages` is Billing's subscription plans** (D13-1), a
> different thing.

### Public catalogue

#### `GET /v1/courses`
**Purpose:** Browse the resolved tenant's published catalogue. `view` switches granularity:
courses (default), individually-sellable **lessons**, or sellable **content packages**.
**Auth:** 🔓 Public
**Middleware:** `tenant` (EnsureRegisteredDomain → ResolveTenant)

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.app` |
| Accept | yes | `application/json` |
| X-Tenant | dev only | `academy` (overrides host in non-prod) |

**Query params**
| Param | Type | Description |
|---|---|---|
| `view` | enum `lessons\|packages` | **B19.** Omitted → courses (below). `lessons` → published, individually-`is_purchasable` standalone lessons (`LessonResource`). `packages` → `is_purchasable` recursive content packages (`PackageResource`, with `items_count`). An unknown value → `422`. |
| `access_mode` | enum `center\|online\|both` | **B19.** Channel filter on all three views. Reuses `AccessMode::isVisibleTo`, so `both` content always matches, `center`→`{center,both}`, `online`→`{online,both}`, and `both`→ every channel. |
| `academic_year` | uuid | **B19.** `view=lessons\|packages` only — narrow to one academic year. An unknown/foreign uuid yields an empty page (never leaks other years). |
| `filter[category_id]` | int | Courses view — restrict to one category. |
| `filter[grade]` | string | Courses view — match courses whose category has this `grade`. |
| `filter[subject]` | string | Courses view — match courses whose category has this `subject`. |
| `q` | string | Case-insensitive `LIKE` on course/lesson `title` or package `name`. |
| `page` | int | Page number (results are 20 per page, fixed). |

The courses view sorts newest-first (`latest()` on `created_at`); `lessons` sort by `sort_order` then
`id`, `packages` by `name` then `id`. There is no `sort` param and `per_page` is not honored (fixed at 20).

**Request body:** None

**Response** `200 OK` — collection of `CourseResource` (default view):
```json
{
  "data": [
    {
      "uuid": "9b1f2c34-5d6e-4a7b-8c90-1112a3b4c5d6",
      "title": "فيزياء الصف الثالث الثانوي",
      "subtitle": "Mechanics to Modern Physics",
      "slug": "physics-grade-12",
      "description": "Full year coverage with solved problems.",
      "category": { "id": 1, "name": "Grade 12 · Physics" },
      "price_minor": 50000,
      "currency": "EGP",
      "access_days": 365,
      "visibility": "visible",
      "publish_at": "2026-06-01T09:00:00+00:00",
      "is_free": false,
      "purchase_enabled": true,
      "access_mode": "online",
      "cover_url": "https://cdn.example.com/course-cover.jpg",
      "thumbnail_url": "https://cdn.example.com/course-thumb.jpg",
      "promo_video_url": "https://youtube.com/watch?v=abc123",
      "points": 100
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "from": 1, "to": 20, "total": 47 }
}
```
For `view=lessons` the items are `LessonResource` (see [Teacher · Lessons](#teacher--lessons)); for
`view=packages` they are `PackageResource` with `items_count` (see [Teacher · Content packages](#teacher--content-packages)).
**Errors:** `403` unregistered/suspended host (domain gate); `422` unknown `view`.

---

#### `GET /v1/courses/{course:slug}`
**Purpose:** Public course detail — marketing copy for the storefront course page.
**Auth:** 🔓 Public
**Middleware:** `tenant`

**Path params**
| Param | Type | Description |
|---|---|---|
| `course` | slug | Course slug (tenant-scoped). Unpublished/hidden/scheduled courses `404`. |

**Request body:** None

**Response** `200 OK` — `CourseDetailResource` (marketing copy fields):
```json
{
  "data": {
    "uuid": "9b1f2c34-5d6e-4a7b-8c90-1112a3b4c5d6",
    "title": "فيزياء الصف الثالث الثانوي",
    "subtitle": "Mechanics to Modern Physics",
    "slug": "physics-grade-12",
    "description": "Full year coverage with solved problems.",
    "learning_outcomes": ["Solve kinematics problems", "Understand electromagnetism"],
    "requirements": ["Basic algebra"],
    "audience": ["Grade 12 science students"],
    "parts": [ { "title": "Mechanics", "lessons_count": 12, "duration_min": 480 } ],
    "cover_url": "https://cdn.example.com/course-cover.jpg",
    "thumbnail_url": "https://cdn.example.com/course-thumb.jpg",
    "promo_video_url": "https://youtube.com/watch?v=abc123",
    "price_minor": 50000,
    "currency": "EGP",
    "is_free": false,
    "access_days": 365,
    "category": { "id": 1, "name": "Grade 12 · Physics" }
  }
}
```
> The `parts` array here is the course's marketing **outline copy** (free-text titles + counts), not the
> lesson-`parts` authoring model. Course detail no longer nests `units`/`lessons` (units retired).

**Errors:** `404` slug not found in tenant, or course not published.

---

### Teacher · Academic years

Top-level content containers (VD change set). Every lesson / part / package / package-type belongs to one
academic year. Bind by `uuid`; **not** behind the `academic-year` middleware (this is where years are
managed, so no year context is needed).

Common headers for every teacher endpoint:

| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.app` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <sanctum-token>` |
| X-Tenant | dev only | `academy` |
| X-Academic-Year | year-scoped routes only | `<year-uuid>` |
| Content-Type | JSON bodies | `application/json` |

All teacher routes share middleware **`tenant` + `auth:sanctum` + `active` + `role:teacher`** (year-scoped
groups add `academic-year`).

#### `GET /v1/teacher/academic-years`
List the teacher's academic years (ordered `sort_order`, then `id`; 20/page).
**Response** `200 OK` — collection of `AcademicYearResource` + `meta`:
```json
{ "data": [ { "id": "3f7c…-year-uuid", "name": "2026 / 2027", "sort_order": 0, "created_at": "2026-08-01T09:00:00+00:00" } ] }
```
> Note: the resource's `id` field **is the uuid** (the public route key); the internal bigint id is not exposed.

#### `POST /v1/teacher/academic-years`
**Request body** (`AcademicYearRequest`):
| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `sort_order` | nullable, integer, min 0 |

**Response** `201 Created` — `AcademicYearResource`. **Errors:** `422` validation.

#### `GET /v1/teacher/academic-years/{academicYear:uuid}`
Show one year. **Response** `200 OK` — `AcademicYearResource`. **Errors:** `404` not in tenant.

#### `PUT /v1/teacher/academic-years/{academicYear:uuid}`
Update (same rules as create). **Response** `200 OK` — `AcademicYearResource`.

#### `DELETE /v1/teacher/academic-years/{academicYear:uuid}`
**Guarded delete** — the body must confirm the name.
| Field | Rules |
|---|---|
| `confirm_name` | required, must **exactly equal** the year's `name` |

Runs in a transaction (cascades the year's content). **Response** `204 No Content`.
**Errors:** `422` `confirm_name` mismatch; `404` not in tenant.

---

### Teacher · Categories

Course taxonomy (grade/subject/level/section). Not year-scoped. Bind by `id`.

#### `GET /v1/teacher/categories`
List the teacher's categories (ordered by `sort_order`, then `name`; not paginated).
**Response** `200 OK` — collection of `CategoryResource`:
```json
{ "data": [ { "id": 1, "name": "Grade 12 · Physics", "grade": "12", "subject": "Physics", "level": "Secondary", "section": "Science", "sort_order": 1 } ] }
```

#### `POST /v1/teacher/categories`
**Request body** (`CategoryRequest`):
| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `grade` / `subject` / `level` / `section` | nullable, string, max 100 |
| `sort_order` | nullable, integer, min 0 |

**Response** `201 Created` — `CategoryResource`. **Errors:** `422` validation.

#### `PUT /v1/teacher/categories/{category}` · `DELETE /v1/teacher/categories/{category}`
Update / delete a category (`category` = id, tenant-scoped). Update body = create rules.
**Response** `200 OK` `CategoryResource` / `204 No Content`. **Errors:** `404` not in tenant; `422` validation.

---

### Teacher · Courses

Teacher course **CRUD is retired** — only the read-only lister survives, as the picker source for the
features that still scope to a course (coupons, reviews, center activation-codes).

#### `GET /v1/teacher/courses`
List all of the teacher's courses (any visibility), newest first (20/page).
**Response** `200 OK` — collection of `CourseResource` + `meta` (same item shape as `GET /v1/courses`).
There is **no** create/show/update/delete here.

---

### Teacher · Lessons

**Standalone** lessons within the active academic year. Year-scoped: every request carries
`X-Academic-Year`; `{lesson}` binds by **id** within that year (a lesson from another year/tenant `404`s).
`academic_year_id` is taken from the header context, never the body (LP-10).

> **Dual video source:** a lesson can carry **both** a protected uploaded video (`video_asset_id` →
> `hls_video`) and a YouTube link (`youtube_url`). `active_video_source` (`upload`|`youtube`, default
> `upload`) is the teacher toggle deciding which one students receive; the inactive source stays stored but
> is never exposed. Activating `youtube` requires a valid `youtube_url` (else `422`). See
> [`../design/lesson-video-sources.md`](../design/lesson-video-sources.md) and the playback contract in
> [`media.md`](media.md).

#### `GET /v1/teacher/lessons`
List the active year's lessons (with video asset + attachments), ordered by `sort_order` then `id`.
**Not paginated.**
**Middleware:** `…role:teacher`, `academic-year`

**Query params**
| Param | Type | Description |
|---|---|---|
| `access_mode` | enum `center\|online\|both` | Filter by channel (`isVisibleTo`). |
| `search` | string | Case-insensitive `LIKE` on `title`. |

**Response** `200 OK` — collection of `LessonResource`:
```json
{
  "data": [
    {
      "id": 101,
      "name": "Displacement & Velocity",
      "title": "Displacement & Velocity",
      "access_mode": "both",
      "price_minor": 5000,
      "currency": "EGP",
      "is_purchasable": true,
      "academic_year_id": "3f7c…-year-uuid",
      "description": "Intro to 1-D motion.",
      "sort_order": 1,
      "duration_sec": 720,
      "max_views": 3,
      "is_free_preview": true,
      "has_video": true,
      "active_video_source": "upload",
      "youtube_url": null,
      "visibility": "visible",
      "publish_at": null,
      "availability_days": 7,
      "max_extensions": 1,
      "extension_hours": 24,
      "self_reopen_limit": 2,
      "unit_id": null,
      "course_id": null,
      "video": { "uuid": "af23…", "type": "hls_video", "status": "ready", "downloadable": false, "duration_sec": 720 },
      "attachments": [ { "uuid": "b7c8…", "type": "pdf", "status": "ready", "title": "Worksheet 1", "url": "https://…/abc.pdf", "downloadable": true } ]
    }
  ]
}
```
Notes: `name` and `title` are the same value (the request field is `name`, the column is `title`).
`has_video` is **source-aware** (true when the *active* source is populated). `unit_id` / `course_id` are
**dormant** (units retired) and usually null. `sections` (parts) are included when eager-loaded.

#### `POST /v1/teacher/lessons`
Create a standalone lesson in the active year.
**Request body** (`LessonRequest`):
| Field | Rules |
|---|---|
| `name` | required, string, max 255 (stored as `title`) |
| `access_mode` | required, enum `center\|online\|both` |
| `price_minor` | nullable, integer, min 0 |
| `currency` | nullable, string, exactly 3 chars |
| `is_purchasable` | boolean |
| `availability_days` | nullable, integer 0–3650 (0/null = unlimited) |
| `description` | nullable, string |
| `is_free_preview` | boolean |
| `sort_order` | nullable, integer, min 0 |
| `duration_sec` | nullable, integer, min 0 |
| `max_views` | nullable, integer, min 1 (per-student playback cap) |
| `visibility` | nullable, enum `visible\|hidden\|scheduled` |
| `publish_at` | nullable, date |
| `youtube_url` | nullable, string ≤2048; must be a valid YouTube link |
| `active_video_source` | nullable, enum `upload\|youtube` (default `upload`) |

Notes: `video_asset_id` is assigned by the [Media](media.md) upload step, not accepted here.
`academic_year_id` comes from `X-Academic-Year`. Setting `active_video_source: youtube` requires an
effective `youtube_url` (body or stored), else `422`.

**Response** `201 Created` — `LessonResource`. **Errors:** `422` validation.

#### `GET /v1/teacher/lessons/{lesson}` · `PUT` · `DELETE`
Show / update / delete one lesson (id, in the active year). Update body = create rules (`name` and
`access_mode` become `sometimes`); **narrowing `access_mode`** is rejected if it would orphan a wider part
(`LessonAccessModeGuard`). **Response** `200 OK` `LessonResource` / `204 No Content`.
**Errors:** `404` not in tenant/year; `422` validation.

---

### Teacher · Lesson parts

Ordered **parts** of a lesson (`lesson_sections`; FR-M04-01). Year-scoped; bind `{section}` by `id` and
assert `section.lesson_id === lesson.id`. `reorder` is registered **before** `{section}` so the literal
path isn't captured as an id. A `video` part references a `media_asset_id` or `youtube_url`; a `homework` /
`quiz` part is backed by an `Exam` row the controller creates/updates (holds degree + grading). Each part's
`access_mode` must be ⊆ its lesson's.

#### `GET /v1/teacher/lessons/{lesson}/sections`
List a lesson's parts (ordered by `sort_order`).
**Response** `200 OK` — collection of `LessonSectionResource`:
```json
{
  "data": [
    { "id": 5, "lesson_id": 101, "type": "video", "name": "Lecture", "title": "Lecture",
      "access_mode": "both", "delivery": null, "gate_rule": null, "max_tries": null,
      "sort_order": 1, "media_asset_id": 88, "youtube_url": null, "pdf_kind": null, "is_required": true },
    { "id": 6, "lesson_id": 101, "type": "quiz", "name": "Checkpoint", "title": "Checkpoint",
      "access_mode": "online", "delivery": "bubble_sheet", "gate_rule": "must_pass", "max_tries": 2,
      "sort_order": 2, "media_asset_id": null, "youtube_url": null, "pdf_kind": null, "is_required": true,
      "exam": { "id": "e1a2…-exam-uuid", "type": "quiz", "grading_mode": "auto", "pass_mode": "percent", "pass_value": 50, "total_marks": null, "duration_min": 15, "is_published": true } }
  ]
}
```
> The resource no longer emits `dependencies` (the old content-dependency CRUD is retired). On the
> **student** listing (`GET /lessons/{lesson}/sections`) the same resource adds a computed `locked` flag
> (from `gate_rule`) and a per-part `result`.

#### `POST /v1/teacher/lessons/{lesson}/sections`
Create a part.
**Request body** (`LessonSectionRequest`):
| Field | Rules |
|---|---|
| `type` | required, enum `video\|homework\|quiz` |
| `name` | nullable, string, max 255 (stored as `title`) |
| `access_mode` | required, enum `center\|online\|both` (⊆ the lesson's) |
| `is_required` | boolean (DB default true) |
| `sort_order` | nullable, integer, min 0 |
| `media_asset_id` | nullable, integer ≥1 (a `video` part may use this or `youtube_url`) |
| `youtube_url` | nullable, string ≤2048, YouTube-valid |
| `delivery` | nullable, enum `SectionDelivery` — **required if** `type` ∈ {homework, quiz} |
| `grading_mode` | nullable, enum — required if homework/quiz (`auto` ⇒ `delivery=bubble_sheet`) |
| `pass_mode` | nullable, enum `percent\|marks` — required if homework/quiz |
| `pass_value` | nullable, numeric, min 0 — required if homework/quiz (`percent` ⇒ 0–100; `marks` ⇒ ≤ `total_marks`) |
| `total_marks` | nullable, numeric, min 0 — required if `pass_mode=marks` |
| `gate_rule` | nullable, enum `GateRule` — required if homework/quiz (the unlock rule for the next part) |
| `max_tries` | nullable, integer, min 1 (retake cap; enforced by [Assessment](assessment.md) attempts) |
| `duration_min` | nullable, integer, min 1 (quiz time cap) |

Notes: a `video` part needs an asset **or** a `youtube_url`; `video_upload` delivery can't back a
quiz/homework. `exam_id` is set by the controller from the homework/quiz fields, not the body.

**Response** `201 Created` — `LessonSectionResource`. **Errors:** `422` validation (missing payload for
the type, part `access_mode` wider than the lesson's).

#### `PUT /v1/teacher/lessons/{lesson}/sections/{section}` · `DELETE …/sections/{section}`
Update / delete a part (same body/rules as create). **Response** `200 OK` `LessonSectionResource` /
`204 No Content`. **Errors:** `404` part not in lesson; `422` validation.

#### `PUT /v1/teacher/lessons/{lesson}/sections/reorder`
Re-sort the lesson's parts.
**Request body:** `{ "order": [6, 5, 8] }` — `order` required array (min 1) of part ids that belong to the
lesson (a foreign id → `422`); `sort_order` is set to the array position (partial reorder allowed).
**Response** `200 OK` — the reordered `LessonSectionResource` collection.

#### `POST /v1/teacher/lessons/{lesson}/sections/{section}/pass-override` · `DELETE …/pass-override/{user:uuid}`
**(LP-D3, `permission:homework`.)** Manually mark one student as having passed a `must_pass` part,
unblocking the gate without a real attempt. Year-scoped.
- **store body:** `user_id` required (a **student uuid**); `note` nullable, string, max 1000. Duplicate → `409`.
  **Response** `201 Created` — `PartPassOverrideResource`.
- **destroy:** idempotent — `204 No Content` even if no override existed. `{user}` binds by uuid,
  resolved independently of `{section}` (`withoutScopedBindings`).

---

### Teacher · Content packages

**Recursive** packages within the active academic year (VD §8.4, doc 13 Phase 5). Base path
**`content-packages`** — `/teacher/packages` is [Billing](billing.md)'s subscription plans (D13-1), do not
confuse. Year-scoped; `{package}` / `{item}` bind by **id** in the active year. `items/reorder` is
registered before `{item}`.

#### `GET /v1/teacher/content-packages`
List the year's packages (`items_count` included; 20/page).
**Query params:** `access_mode` (channel filter), `search` (name `LIKE`).
**Response** `200 OK` — collection of `PackageResource` + `meta`.

#### `POST /v1/teacher/content-packages`
Create a package.
**Request body** (`PackageRequest`):
| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `access_mode` | required, enum `center\|online\|both` |
| `price_minor` | nullable, integer, min 0 |
| `currency` | nullable, string, exactly 3 chars |
| `is_purchasable` | boolean |
| `package_type_id` | nullable, integer — must be a [PackageType](#teacher--package-types) in this tenant + active year, else `422` |

`academic_year_id` comes from `X-Academic-Year`, not the body. **Response** `201 Created` — `PackageResource`:
```json
{
  "data": {
    "id": 12, "uuid": "7a2d…-package-uuid", "name": "Term 1 Package",
    "access_mode": "both", "price_minor": 20000, "currency": "EGP", "is_purchasable": true,
    "type": { "id": 3, "uuid": "9c…-type-uuid", "name": "Terms" },
    "academic_year_id": "3f7c…-year-uuid", "items_count": 4,
    "created_at": "2026-08-10T09:00:00+00:00"
  }
}
```
`type` is present only when `package_type_id` is set.

#### `GET /v1/teacher/content-packages/{package}` · `PUT` · `DELETE`
Show / update / delete one package (id, active year). Update body = create rules (`name` / `access_mode`
`sometimes`); narrowing `access_mode` re-checked against items. **Response** `200 OK` `PackageResource`
(with `items`) / `204 No Content`. **Errors:** `404` not in tenant/year; `422` validation.

#### `POST /v1/teacher/content-packages/{package}/items`
Add an item — a **lesson** or a **sub-package** (recursive).
**Request body:**
| Field | Rules |
|---|---|
| `item_type` | required, `lesson` \| `package` |
| `item_id` | required, integer — the target's **internal id** (lessons have no uuid) |

Attach goes through `PackageItemService` (enforces the access-mode ceiling, cycle prevention, and
same-year guards). **Response** `201 Created` — `PackageItemResource`:
```json
{ "data": { "id": 30, "item_type": "lesson", "item_id": 101, "sort_order": 0,
  "item": { "id": 101, "type": "lesson", "name": "Displacement & Velocity", "access_mode": "both", "price_minor": 5000, "currency": "EGP", "is_purchasable": true } } }
```
**Errors:** `422` cycle, cross-year, or a channel wider than the package's.

#### `PUT /v1/teacher/content-packages/{package}/items/reorder`
`{ "order": [30, 31] }` — `order` required array (min 1) of the package's item ids.
**Response** `200 OK` — the reordered `PackageItemResource` collection.

#### `DELETE /v1/teacher/content-packages/{package}/items/{item}`
Remove one item (id). **Response** `204 No Content`.

---

### Teacher · Package types

**(B27)** Teacher-managed content-package categories, scoped to tenant + active academic year. Year-scoped;
bind `{packageType}` by `uuid`. A [package](#teacher--content-packages) optionally links one via
`package_type_id`; deleting a type **nulls** that reference (packages survive — `nullOnDelete`). Not
available to assistants (this block is `role:teacher`).

#### `GET /v1/teacher/package-types`
List the year's package types (ordered `sort_order`, then `id`; 20/page).
**Response** `200 OK` — collection of `PackageTypeResource` + `meta`:
```json
{ "data": [ { "id": 3, "uuid": "9c…-type-uuid", "name": "Terms", "sort_order": 0, "description": "Termly bundles", "created_at": "2026-08-13T09:00:00+00:00" } ] }
```
> `id` is the internal bigint used as `package_type_id`; `uuid` is the public route handle.

#### `POST /v1/teacher/package-types`
**Request body** (`PackageTypeRequest`):
| Field | Rules |
|---|---|
| `name` | required, string, max 255 — **unique within tenant + academic year** |
| `sort_order` | nullable, integer, min 0 |
| `description` | nullable, string, max 2000 |

**Response** `201 Created` — `PackageTypeResource`. **Errors:** `422` validation (duplicate name → "A
package type with this name already exists for this academic year.").

#### `GET /v1/teacher/package-types/{packageType:uuid}` · `PUT` · `DELETE`
Show / update / delete one type. Update: `name` becomes `sometimes` (uniqueness ignores self).
**Response** `200 OK` `PackageTypeResource` / `204 No Content`. **Errors:** `404` not in tenant/year; `422` validation.

---

### Teacher · Attachments

Lesson materials are `MediaAsset` rows of type `pdf` / `file` / `link` (the video is separate). Not
year-scoped in the route path; `{lesson}` binds by id, `{attachment}` by uuid.

#### `GET /v1/teacher/lessons/{lesson}/attachments`
List a lesson's attachments (type != `hls_video`), ordered by `sort_order` (not paginated).
**Response** `200 OK` — collection of `MediaAssetResource`:
```json
{ "data": [ { "uuid": "b7c8…", "type": "pdf", "status": "ready", "title": "Worksheet 1", "url": "https://…/abc.pdf", "thumbnail_url": null, "downloadable": true, "duration_sec": null } ] }
```

#### `POST /v1/teacher/lessons/{lesson}/attachments`
Add an attachment (uploaded PDF/file, or an external link). Files store on the `public` disk; the asset is
created `ready`.
**Request headers:** `Content-Type: multipart/form-data` for `pdf`/`file`; a `link` may be JSON or multipart.
**Request body** (`AttachmentRequest`):
| Field | Rules |
|---|---|
| `type` | required, one of `pdf`, `file`, `link` |
| `title` | nullable, string, max 255 |
| `url` | nullable, url, max 2048 — **required if `type=link`** |
| `file` | nullable, uploaded file, max 20480 KB (20 MB) — **required if `type=pdf` or `type=file`** |
| `downloadable` | boolean (default false) |

**Response** `201 Created` — `MediaAssetResource`. **Errors:** `404` lesson not found; `422` validation
(missing `file`/`url`, file > 20 MB).

#### `DELETE /v1/teacher/lessons/{lesson}/attachments/{attachment:uuid}`
Delete a lesson attachment (also removes an uploaded file). **Response** `204 No Content`.
**Errors:** `404` not on lesson, or the target is the lesson's `hls_video` (not deletable here).

---

### Teacher · Lesson availability (time-box)

Configure the per-lesson access window. `availability_days: null` = unlimited (no window). `{lesson}`
binds by id.

#### `GET /v1/teacher/lessons/{lesson}/availability`
**Response** `200 OK`:
```json
{ "data": { "lesson_id": 101, "availability_days": 7, "max_extensions": 1, "extension_hours": 24, "self_reopen_limit": 2 } }
```

#### `PUT /v1/teacher/lessons/{lesson}/availability`
**Request body** (`LessonAvailabilityRequest`):
| Field | Rules |
|---|---|
| `availability_days` | present, nullable, integer 1–3650 (null = unlimited) |
| `max_extensions` | nullable, integer 0–100 (staff-approval budget) |
| `extension_hours` | nullable, integer 1–8760 |
| `self_reopen_limit` | nullable, integer 0–100 — auto self-reopen budget (VD R3/R4). 0 = disabled. Shares `extensions_used` with `max_extensions`; set `max_extensions > self_reopen_limit` to keep a staff-approval fallback past the auto cap. |

**Response** `200 OK` — same payload as `GET`.

#### `POST /v1/teacher/lessons/{lesson}/reopen`
**(doc 11 R4.)** Staff manually opens one lesson for one student for a custom number of hours.
**Request body:** the target `user` + `hours` (staff-chosen extension). **Response** `200 OK` — the
refreshed window. **Errors:** `404` lesson/student not in tenant.

---

### Teacher · Extension requests

Staff review of student window-extension requests.

#### `GET /v1/teacher/extension-requests`
List **pending** requests across the academy's lessons (newest first).
**Response** `200 OK` — collection of `LessonExtensionRequestResource`.

#### `POST /v1/teacher/extension-requests/{extensionRequest}/grant` · `…/deny`
Decide a pending request. **Grant** pushes `expires_at` out by the lesson's `extension_hours`, clears the
lock, and consumes one extension.
**Response** `200 OK`:
```json
{ "data": { "id": 3, "access_window_id": 9, "user_id": 51, "lesson_id": 101, "status": "granted", "requested_at": "…", "decided_at": "…" } }
```
**Errors:** `404` request not in tenant; `409` already decided.

---

### Student · Lesson content & access

Student-facing views of the content model. Auth: `auth:sanctum` + `active` membership (any role); lesson
access is checked (free-preview lessons are open, otherwise an enrollment is required).

#### `GET /v1/lessons/{lesson}/sections`
The student's ordered parts, each with a computed **`locked`** flag from the part `gate_rule`
(part-gating, LP-13/14) and a per-part `result`. Also filtered by the student's `study_mode` vs each
part's `access_mode` (B12/LP-6: a `both` part is visible to either channel).
**Response** `200 OK` — collection of `LessonSectionResource`, each with `"locked": true|false`.
**Errors:** `403` no lesson access.

#### `POST /v1/lessons/{lesson}/start`
Confirm + open the access window (starts the countdown). Idempotent — returns the running window if
already started; no-op for unlimited lessons.
**Response** `200 OK`:
```json
{
  "data": {
    "lesson_id": 101, "has_window": true, "availability_days": 7,
    "max_extensions": 1, "extension_hours": 24, "started": true,
    "started_at": "2026-07-28T09:00:00+00:00", "expires_at": "2026-08-04T09:00:00+00:00",
    "remaining_sec": 604800, "locked": false, "extensions_used": 0,
    "self_reopen_limit": 2, "self_reopens_remaining": 2, "can_self_reopen": false
  }
}
```
**Errors:** `403` no lesson access.

#### `GET /v1/lessons/{lesson}/access`
Countdown state for the timer (same payload shape as `start`; `started:false` + null window fields when not
yet started). `remaining_sec` is server-computed. **Response** `200 OK`.

#### `POST /v1/lessons/{lesson}/reopen`
**Auto self-reopen (VD R3/R4).** Instantly, with no staff approval, extend the student's own
**expired/locked** window by `extension_hours` from now and consume one from the shared `extensions_used`
counter — while `extensions_used < self_reopen_limit`. Server-authoritative (client-sent counts ignored).
No body. **Response** `200 OK` — same payload as `/access`.
**Errors:** `403` no lesson access; `409` window not started, still open, or **`reopen_limit_reached`**
(fall back to `extension-request`).

#### `POST /v1/lessons/{lesson}/extension-request`
Request more time from staff — the after-limit fallback once auto self-reopen is spent. Requires a started
window, `max_extensions > 0`, remaining allowance, and no pending request.
**Response** `201 Created` — `LessonExtensionRequestResource`.
**Errors:** `403` no lesson access; `409` not started / disabled / none remaining / already pending.

---

### Student · Library (VD F1)

The student's purchased content — standalone lessons and packages they own (access from `enrollments`).

#### `GET /v1/me/lessons`
The student's purchased standalone lessons (distinct `lesson_id`s from access-granting enrollments;
ordered `sort_order`, `id`). **Not** paginated; a hand-built `{ "data": [...] }` (not `LessonResource`).
Each row: `id`, `name` (=title), `title`, `access_mode`, `price_minor`, `currency`, `course_id`,
`course_slug`, `course_title`, `completed` (from `lesson_progress.completed_at`).
**Auth:** 👤 active member.

#### `GET /v1/me/packages`
The student's purchased packages (distinct `package_id`s from access-granting enrollments — package-buy
provenance; ordered `name`, `id`; 20/page). **Response** `200 OK` — paginated `PackageResource` collection
(with `items_count`).
**Auth:** 👤 active member.
