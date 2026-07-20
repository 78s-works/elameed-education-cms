# Catalog Module

The Catalog module (`app/Modules/Catalog`, M04) owns the tenant's course library: the public course
storefront that prospective students browse, and the teacher-facing authoring surface for the
`course → unit → lesson → attachment` hierarchy plus the course taxonomy (categories). Every route
runs inside the `tenant` middleware group, so the tenant is resolved from the request host (or the
`X-Tenant` dev override) and all queries are tenant-isolated by the `BelongsToTenant` global scope.
Public listings expose only the resolved tenant's **published** content (visible + past `publish_at`);
teachers see all of their own content regardless of visibility.

## Conventions (apply to every endpoint)

- Base prefix `/api/v1`. Success responses are `{ "data": ... }`; paginated lists add a `"meta"`
  block (`current_page`, `last_page`, `per_page`, `total`, `from`, `to`). Errors are
  `{ "error": { "code", "message", "details" } }`.
- Money is an integer in minor units (`price_minor`) paired with a 3-letter `currency`.
- Timestamps are ISO-8601 UTC (e.g. `publish_at`). Arabic content is UTF-8.
- **Tenancy:** tenant resolved from the `Host` header (dev override: `X-Tenant: <slug>`). Enforced by
  the `tenant` middleware group (`EnsureRegisteredDomain` → `ResolveTenant`), which binds the tenant
  before route-model binding so a bound model can never cross tenants.
- **Auth:** Sanctum bearer token. Public browse needs no token. Teacher routes require
  `auth:sanctum` + `active` (active tenant membership) + `role:teacher`.
- **Route binding keys:** courses bind by `uuid` on teacher routes and by `slug` on public routes
  (no id enumeration); nested units and lessons bind by `id` (own tenant data); attachments bind by
  `uuid`. Nested controllers additionally assert parent ownership (`unit.course_id === course.id`,
  `lesson.unit_id === unit.id`, `attachment.lesson_id === lesson.id`) and `404` on mismatch.

## Models

| Model | Table | One-liner |
|---|---|---|
| `CourseCategory` | `course_categories` | Teacher's taxonomy (name + grade/subject/level/section + sort_order). A course optionally belongs to one. |
| `Course` | `courses` | The top-level product. Binds by `uuid` (teacher) / `slug` (public). Soft-deletes. Holds pricing, visibility, marketing copy (`learning_outcomes`, `requirements`, `audience`, `parts`). |
| `Unit` | `units` | A section within a course (`course_id`), ordered by `sort_order`, with its own visibility. |
| `Lesson` | `lessons` | A leaf under a unit (`unit_id` + inherited `course_id`). Has one video (`video_asset_id`) and many attachments. Carries `duration_sec`, `max_views`, `is_free_preview`, `gating_rule`. |
| `LessonAttachment` | *(stored as `media_assets`)* | Not a dedicated model — attachments are `MediaAsset` rows of type `pdf` / `file` / `link` linked by `lesson_id`. The lesson's single `hls_video` asset is **not** an attachment. |

**Hierarchy:** `Course` **hasMany** `Unit` **hasMany** `Lesson`. A lesson also stores `course_id`
directly (copied from its unit on create) so it always agrees with its unit's course. A lesson
**hasMany** attachments (MediaAsset, type != `hls_video`) and **belongsTo** one `videoAsset`.

### Key enums

- **`ContentVisibility`** (`App\Modules\Catalog\Enums`) — applies to course/unit/lesson `visibility`:
  - `visible` — publicly listed (subject to `publish_at`)
  - `hidden` — not listed
  - `scheduled` — becomes visible at `publish_at`
  - DB/model default: `hidden` for a **course**, `visible` for **units** and **lessons**.
  - "Published" = `visibility === visible` **AND** (`publish_at` is null OR `publish_at <= now()`).
    Note: a course with `visibility: scheduled` is **never** published by this rule; scheduling a
    course means setting `visibility: visible` + a future `publish_at`.
- **`MediaType`** (`App\Modules\Media\Enums`) — attachment `type`: `pdf`, `file`, `link`
  (plus `hls_video` for the lesson video, excluded from attachments).
- **`MediaStatus`** (`App\Modules\Media\Enums`) — attachments are created `ready`; videos move
  `uploading → transcoding → ready|failed`.

---

## Endpoints

23 endpoints total: 2 public catalogue + 21 teacher authoring (4 categories, 5 courses, 4 units,
4 lessons, 3 attachments; the 21st is the 5th course route `show`).

### Public catalogue

#### `GET /v1/courses`
**Purpose:** List the resolved tenant's published courses (storefront grid).
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
| `filter[category_id]` | int | Restrict to one category. |
| `filter[grade]` | string | Match courses whose category has this `grade`. |
| `filter[subject]` | string | Match courses whose category has this `subject`. |
| `q` | string | Case-insensitive `LIKE` on course `title`. |
| `page` | int | Page number (results are 20 per page, fixed). |

Results are always sorted newest-first (`latest()` on `created_at`); there is no `sort` param and
`per_page` is not honored (fixed at 20).

**Request body:** None

**Response** `200 OK` — collection of `CourseResource`:
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
      "is_center": false,
      "cover_url": "https://cdn.example.com/course-cover.jpg",
      "thumbnail_url": "https://cdn.example.com/course-thumb.jpg",
      "promo_video_url": "https://youtube.com/watch?v=abc123",
      "points": 100
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "from": 1,
    "to": 20,
    "total": 47
  }
}
```
**Errors:** `403` unregistered/suspended host (domain gate).

---

#### `GET /v1/courses/{course:slug}`
**Purpose:** Public course detail — the outline a prospective student sees (marketing copy + published
units/lessons with preview flags only; playback is gated elsewhere).
**Auth:** 🔓 Public
**Middleware:** `tenant`

**Request headers**
| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.app` |
| Accept | yes | `application/json` |
| X-Tenant | dev only | `academy` |

**Path params**
| Param | Type | Description |
|---|---|---|
| `course` | slug | Course slug (tenant-scoped). Unpublished/hidden/scheduled courses `404`. |

**Request body:** None

**Response** `200 OK` — `CourseDetailResource` (only `published()` units, each with only `published()`
lessons, both ordered by `sort_order`):
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
    "parts": [
      { "title": "Mechanics", "lessons_count": 12, "duration_min": 480 }
    ],
    "cover_url": "https://cdn.example.com/course-cover.jpg",
    "thumbnail_url": "https://cdn.example.com/course-thumb.jpg",
    "promo_video_url": "https://youtube.com/watch?v=abc123",
    "price_minor": 50000,
    "currency": "EGP",
    "is_free": false,
    "access_days": 365,
    "category": { "id": 1, "name": "Grade 12 · Physics" },
    "units": [
      {
        "id": 10,
        "title": "Kinematics",
        "sort_order": 1,
        "lessons": [
          {
            "id": 101,
            "title": "Displacement & Velocity",
            "duration_sec": 720,
            "is_free_preview": true,
            "has_video": true
          }
        ]
      }
    ]
  }
}
```
**Errors:** `404` slug not found in tenant, or course not published.

---

### Teacher · Categories

Common headers for every teacher endpoint:

| Header | Required | Example |
|---|---|---|
| Host | yes | `academy.elameed.app` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <sanctum-token>` |
| X-Tenant | dev only | `academy` |
| Content-Type | JSON bodies | `application/json` |

All teacher routes share middleware: **`tenant` + `auth:sanctum` + `active` + `role:teacher`**.

#### `GET /v1/teacher/categories`
**Purpose:** List the teacher's categories (ordered by `sort_order`, then `name`).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Request body:** None (not paginated)

**Response** `200 OK` — collection of `CategoryResource`:
```json
{
  "data": [
    {
      "id": 1,
      "name": "Grade 12 · Physics",
      "grade": "12",
      "subject": "Physics",
      "level": "Secondary",
      "section": "Science",
      "sort_order": 1
    }
  ]
}
```

---

#### `POST /v1/teacher/categories`
**Purpose:** Create a category.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Request body** (`CategoryRequest`):
```json
{
  "name": "Grade 12 · Physics",
  "grade": "12",
  "subject": "Physics",
  "level": "Secondary",
  "section": "Science",
  "sort_order": 1
}
```
| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `grade` | nullable, string, max 100 |
| `subject` | nullable, string, max 100 |
| `level` | nullable, string, max 100 |
| `section` | nullable, string, max 100 |
| `sort_order` | nullable, integer, min 0 |

**Response** `201 Created` — `CategoryResource` (same shape as list item).
**Errors:** `422` validation.

---

#### `PUT /v1/teacher/categories/{category}`
**Purpose:** Update a category.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params**
| Param | Type | Description |
|---|---|---|
| `category` | id | Category id (tenant-scoped). |

**Request body:** same fields/rules as create (`CategoryRequest`).

**Response** `200 OK` — `CategoryResource`.
**Errors:** `404` not found in tenant; `422` validation.

---

#### `DELETE /v1/teacher/categories/{category}`
**Purpose:** Delete a category.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `category` (id).
**Request body:** None
**Response** `204 No Content`.
**Errors:** `404` not found in tenant.

---

### Teacher · Courses

#### `GET /v1/teacher/courses`
**Purpose:** List all of the teacher's courses (any visibility), newest first, paginated (20/page).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Query params**
| Param | Type | Description |
|---|---|---|
| `page` | int | Page number (20 per page, fixed). |

**Request body:** None

**Response** `200 OK` — collection of `CourseResource` + `meta` (same item shape as `GET /v1/courses`).

---

#### `POST /v1/teacher/courses`
**Purpose:** Create a course. Slug is auto-generated unique-within-tenant from the title (falls back
to a random stem for non-ASCII/Arabic titles); `tenant_id` is filled automatically.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Request body** (`CourseRequest`):
```json
{
  "title": "Complete Physics - Grade 12",
  "subtitle": "Mechanics to Modern Physics",
  "description": "Full year coverage with solved problems.",
  "learning_outcomes": ["Solve kinematics problems", "Understand electromagnetism"],
  "requirements": ["Basic algebra"],
  "audience": ["Grade 12 science students"],
  "parts": [
    { "title": "Mechanics", "lessons_count": 12, "duration_min": 480 }
  ],
  "category_id": 1,
  "price_minor": 50000,
  "currency": "EGP",
  "access_days": 365,
  "visibility": "visible",
  "publish_at": "2026-08-01T09:00:00Z",
  "is_free": false,
  "purchase_enabled": true,
  "is_center": false,
  "cover_url": "https://cdn.example.com/course-cover.jpg",
  "thumbnail_url": "https://cdn.example.com/course-thumb.jpg",
  "promo_video_url": "https://youtube.com/watch?v=abc123",
  "points": 100
}
```
| Field | Rules |
|---|---|
| `title` | required, string, max 255 (slug derived from this) |
| `subtitle` | nullable, string, max 255 |
| `description` | nullable, string |
| `learning_outcomes` | nullable array (max 30); each string max 300 |
| `requirements` | nullable array (max 30); each string max 300 |
| `audience` | nullable array (max 30); each string max 300 |
| `parts` | nullable array (max 50); each `{ title* (max 255), lessons_count (int ≥0), duration_min (int ≥0) }` |
| `category_id` | nullable; must be a `course_categories.id` **in this tenant** |
| `price_minor` | nullable, integer, min 0 (minor units) |
| `currency` | nullable, string, exactly 3 chars |
| `access_days` | nullable, integer, min 1 |
| `visibility` | nullable, enum `visible\|hidden\|scheduled` (default `hidden`) |
| `publish_at` | nullable, date |
| `is_free` | boolean |
| `purchase_enabled` | boolean |
| `is_center` | boolean |
| `cover_url` | nullable, url, max 2048 (wide hero banner) |
| `thumbnail_url` | nullable, url, max 2048 (small card/grid preview) |
| `promo_video_url` | nullable, url, max 2048 (public teaser) |
| `points` | nullable, integer, min 0 |

Note: `slug` is server-generated and **not** accepted in the body.

**Response** `201 Created` — `CourseResource`.
**Errors:** `422` validation (e.g. `category_id` in another tenant).

---

#### `GET /v1/teacher/courses/{course:uuid}`
**Purpose:** Show one of the teacher's courses (any visibility), with its category.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params**
| Param | Type | Description |
|---|---|---|
| `course` | uuid | Course uuid (tenant-scoped; cross-tenant uuid `404`s). |

**Response** `200 OK` — `CourseResource`.
**Errors:** `404` not found in tenant.

---

#### `PUT /v1/teacher/courses/{course:uuid}`
**Purpose:** Update a course. The slug stays stable across updates so public URLs don't break.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `course` (uuid).
**Request body:** same fields/rules as create (`CourseRequest`). `slug` is never changed here.

**Response** `200 OK` — `CourseResource`.
**Errors:** `404` not found; `422` validation.

---

#### `DELETE /v1/teacher/courses/{course:uuid}`
**Purpose:** Soft-delete a course.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `course` (uuid).
**Request body:** None
**Response** `204 No Content` (soft delete — row retained with `deleted_at`).
**Errors:** `404` not found in tenant.

---

### Teacher · Units

#### `GET /v1/teacher/courses/{course:uuid}/units`
**Purpose:** List a course's units, ordered by `sort_order`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `course` (uuid).
**Request body:** None (not paginated)

**Response** `200 OK` — collection of `UnitResource` (`lessons` omitted here — not eager-loaded):
```json
{
  "data": [
    {
      "id": 10,
      "course_id": "9b1f2c34-5d6e-4a7b-8c90-1112a3b4c5d6",
      "title": "Kinematics",
      "sort_order": 1,
      "visibility": "visible",
      "publish_at": null
    }
  ]
}
```
Note: `course_id` on the unit is the internal numeric course id; the path uses the course uuid.

---

#### `POST /v1/teacher/courses/{course:uuid}/units`
**Purpose:** Create a unit under a course.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `course` (uuid).

**Request body** (`UnitRequest`):
```json
{
  "title": "Kinematics",
  "sort_order": 1,
  "visibility": "visible",
  "publish_at": null
}
```
| Field | Rules |
|---|---|
| `title` | required, string, max 255 |
| `sort_order` | nullable, integer, min 0 |
| `visibility` | nullable, enum `visible\|hidden\|scheduled` (default `visible`) |
| `publish_at` | nullable, date |

**Response** `201 Created` — `UnitResource`.
**Errors:** `404` course not found; `422` validation.

---

#### `PUT /v1/teacher/courses/{course:uuid}/units/{unit}`
**Purpose:** Update a unit.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params**
| Param | Type | Description |
|---|---|---|
| `course` | uuid | Owning course. |
| `unit` | id | Unit id; must belong to `course` or `404`. |

**Request body:** same as create (`UnitRequest`).

**Response** `200 OK` — `UnitResource`.
**Errors:** `404` course/unit not found or unit not in course; `422` validation.

---

#### `DELETE /v1/teacher/courses/{course:uuid}/units/{unit}`
**Purpose:** Delete a unit.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `course` (uuid), `unit` (id, must belong to course).
**Request body:** None
**Response** `204 No Content`.
**Errors:** `404` not found or unit not in course.

---

### Teacher · Lessons

Lessons are addressed under their **unit** (not the course). On create, the lesson inherits
`course_id` from the unit.

#### `GET /v1/teacher/units/{unit}/lessons`
**Purpose:** List a unit's lessons (with video asset + attachments eager-loaded), ordered by `sort_order`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `unit` (id, tenant-scoped).
**Request body:** None (not paginated)

**Response** `200 OK` — collection of `LessonResource`:
```json
{
  "data": [
    {
      "id": 101,
      "unit_id": 10,
      "course_id": 5,
      "title": "Displacement & Velocity",
      "description": "Intro to 1-D motion.",
      "sort_order": 1,
      "duration_sec": 720,
      "max_views": 3,
      "is_free_preview": true,
      "has_video": true,
      "visibility": "visible",
      "publish_at": null,
      "video": {
        "uuid": "af23...",
        "type": "hls_video",
        "status": "ready",
        "title": "Lesson 1 video",
        "url": null,
        "thumbnail_url": "https://cdn.example.com/thumb.jpg",
        "downloadable": false,
        "duration_sec": 720
      },
      "attachments": [
        {
          "uuid": "b7c8...",
          "type": "pdf",
          "status": "ready",
          "title": "Worksheet 1",
          "url": "https://cdn.example.com/storage/attachments/abc.pdf",
          "thumbnail_url": null,
          "downloadable": true,
          "duration_sec": null
        }
      ]
    }
  ]
}
```
Notes: `has_video` is `video_asset_id !== null`. `video` (a single asset) and `attachments`
(MediaAsset, type != `hls_video`) both use `MediaAssetResource`.

---

#### `POST /v1/teacher/units/{unit}/lessons`
**Purpose:** Create a lesson under a unit (`course_id` copied from the unit automatically).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `unit` (id).

**Request body** (`LessonRequest`):
```json
{
  "title": "Displacement & Velocity",
  "description": "Intro to 1-D motion.",
  "sort_order": 1,
  "duration_sec": 720,
  "max_views": 3,
  "is_free_preview": true,
  "visibility": "visible",
  "publish_at": null
}
```
| Field | Rules |
|---|---|
| `title` | required, string, max 255 |
| `description` | nullable, string |
| `sort_order` | nullable, integer, min 0 |
| `duration_sec` | nullable, integer, min 0 |
| `max_views` | nullable, integer, min 1 (per-student playback cap) |
| `is_free_preview` | boolean (free/preview lesson) |
| `visibility` | nullable, enum `visible\|hidden\|scheduled` (default `visible`) |
| `publish_at` | nullable, date |

Note: `video_asset_id` is assigned by the Media step, not accepted here; `course_id` and `unit_id`
are derived server-side, not from the body.

**Response** `201 Created` — `LessonResource` (with `video` + `attachments` loaded).
**Errors:** `404` unit not found; `422` validation.

---

#### `PUT /v1/teacher/units/{unit}/lessons/{lesson}`
**Purpose:** Update a lesson.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params**
| Param | Type | Description |
|---|---|---|
| `unit` | id | Owning unit. |
| `lesson` | id | Lesson id; must belong to `unit` or `404`. |

**Request body:** same as create (`LessonRequest`).

**Response** `200 OK` — `LessonResource`.
**Errors:** `404` unit/lesson not found or lesson not in unit; `422` validation.

---

#### `DELETE /v1/teacher/units/{unit}/lessons/{lesson}`
**Purpose:** Delete a lesson.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `unit` (id), `lesson` (id, must belong to unit).
**Request body:** None
**Response** `204 No Content`.
**Errors:** `404` not found or lesson not in unit.

---

### Teacher · Attachments

Lesson materials are `MediaAsset` rows of type `pdf` / `file` / `link` (the video is separate).
Phase 1 stores uploaded files on the default `public` disk.

#### `GET /v1/teacher/lessons/{lesson}/attachments`
**Purpose:** List a lesson's attachments (type != `hls_video`), ordered by `sort_order`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params:** `lesson` (id).
**Request body:** None (not paginated)

**Response** `200 OK` — collection of `MediaAssetResource`:
```json
{
  "data": [
    {
      "uuid": "b7c8d9e0-1234-4a56-9bcd-ef0123456789",
      "type": "pdf",
      "status": "ready",
      "title": "Worksheet 1",
      "url": "https://cdn.example.com/storage/attachments/abc.pdf",
      "thumbnail_url": null,
      "downloadable": true,
      "duration_sec": null
    }
  ]
}
```

---

#### `POST /v1/teacher/lessons/{lesson}/attachments`
**Purpose:** Add an attachment (uploaded PDF/file, or an external link). Files are stored on the
`public` disk; the asset is created with `status: ready`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Request headers:** `Content-Type: multipart/form-data` for `pdf`/`file` (uploads a `file` part).
A `link` may be sent as JSON or multipart.

**Path params:** `lesson` (id).

**Request body** (`AttachmentRequest`) — multipart form fields:
| Field | Rules |
|---|---|
| `type` | required, one of `pdf`, `file`, `link` |
| `title` | nullable, string, max 255 |
| `url` | nullable, url, max 2048 — **required if `type=link`** |
| `file` | nullable, uploaded file, max 20480 KB (20 MB) — **required if `type=pdf` or `type=file`** |
| `downloadable` | boolean (default false) |

Behavior: for `type=link` the given `url` is stored; for `pdf`/`file` the upload is stored under
`attachments/` on the `public` disk and `url` is set to its public URL (`source_key` holds the path).

Example (link, JSON):
```json
{ "type": "link", "title": "Reference sheet", "url": "https://example.com/ref.pdf", "downloadable": true }
```

**Response** `201 Created` — `MediaAssetResource`:
```json
{
  "data": {
    "uuid": "b7c8d9e0-1234-4a56-9bcd-ef0123456789",
    "type": "pdf",
    "status": "ready",
    "title": "Worksheet 1",
    "url": "https://cdn.example.com/storage/attachments/abc.pdf",
    "thumbnail_url": null,
    "downloadable": true,
    "duration_sec": null
  }
}
```
**Errors:** `404` lesson not found; `422` validation (missing `file` for pdf/file, missing `url` for link, file > 20 MB).

---

#### `DELETE /v1/teacher/lessons/{lesson}/attachments/{attachment:uuid}`
**Purpose:** Delete a lesson attachment (also removes the stored file from the `public` disk when it
was an upload).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** tenant, auth:sanctum, active, role:teacher

**Path params**
| Param | Type | Description |
|---|---|---|
| `lesson` | id | Owning lesson. |
| `attachment` | uuid | MediaAsset uuid; must belong to `lesson` or `404`. |

**Request body:** None
**Response** `204 No Content`.
**Errors:** `404` lesson/attachment not found, attachment not on lesson, or the target is the lesson's
`hls_video` (the video is not deletable via the attachments endpoint).
