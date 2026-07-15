# Engagement Module

> The Engagement module owns the learner's post-enrollment relationship with the academy: **course reviews** (a gated 1–5 rating + comment, upserted one-per-student-per-course and surfaced on the landing `testimonials` section), **lesson progress** tracking (watch %/seconds/position, feeding "continue watching" resume and an activity feed), **favorites** (a student's saved-courses shortlist), and **gamification** (append-only points, threshold-awarded badges, and a leaderboard). Teachers manage their own badge catalog and a single leaderboard-visibility toggle. Every model is tenant-scoped via `BelongsToTenant`; points and badges are tallied with `withoutGlobalScopes()` inside `PointsService` and re-filtered by `tenant_id` explicitly.

## Models

- **`Review`** — A student's rating (`integer` 1–5) + `comment` on a `course` (unique per `course_id` + `user_id`; upserted). Read-only input to the landing `testimonials` section and a course's aggregate rating. Fillable: `course_id`, `user_id`, `rating`, `comment`.
- **`LessonProgress`** — Per-student watch state for a lesson (table `lesson_progress`). Fields: `enrollment_id`, `lesson_id`, `user_id`, `watch_percent`, `watch_seconds`, `sessions_count`, `last_position_sec`, `completed_at` (datetime). Powers resume, activity, and completion points.
- **`Favorite`** — A student's saved course (`user_id` + `course_id`). Tenant-scoped to the current academy.
- **`PointsEntry`** — Append-only ledger row (`const UPDATED_AT = null`). Fields: `user_id`, `points` (integer), `reason`, `ref_type`, `ref_id`, `idempotency_key`. A student's score is `SUM(points)`; the `idempotency_key` guarantees each event scores once.
- **`Badge`** — A teacher-defined award. Fields: `name`, `description`, `icon`, `points_threshold` (integer, nullable). A non-null threshold auto-awards the badge when a student's total crosses it.
- **`StudentBadge`** — Join row recording a badge earned by a student (`user_id`, `badge_id`, `awarded_at`). No timestamps (`CREATED_AT`/`UPDATED_AT` both null); uses `awarded_at` instead.

## Services / Support

- **`PointsService`** — `award()` writes an idempotent `PointsEntry` (key = `{reason}:{refType}:{refId}:{userId}`; duplicate inserts swallowed on unique-violation `23000`) and then auto-awards any threshold badges now crossed. `total()` sums a student's points; `leaderboard()` returns the top-N `{user_id, points}` rows ordered by `SUM(points)`. Called by `ProgressController` on lesson completion.
- **Config `config/gamification.php`** — `lesson_points` (env `GAMIFICATION_LESSON_POINTS`, default **5**), `exam_points` (env `GAMIFICATION_EXAM_POINTS`, default **20**). Progress store awards `lesson_points` with reason `lesson.completed`.
- **`TeacherProfile.hide_ranking`** (owned by the Tenancy module) — the single per-tenant toggle that hides the student leaderboard. Read by `GamificationController@leaderboard`, read/written by the teacher gamification endpoints.

## Key values / enums

- **`rating`**: integer 1–5.
- **Completion threshold**: `watch_percent >= 95` sets `completed_at` and (on first crossing only) awards points.
- **Points `reason`**: e.g. `lesson.completed` (this module). Exams award elsewhere with `exam_points`.
- No formal PHP enums in this module; the above are literal constraints in FormRequests / controllers.

---

## Endpoints

16 endpoints: Reviews (2), Progress (3), Favorites (3), Gamification · Student (3), Gamification · Teacher (5). All sit under the `tenant` middleware group (Host resolution or `X-Tenant` override). Success envelopes are `{ "data": ... }` (paginated lists add `"meta"`); errors are `{ "error": { code, message, details } }`. Timestamps are ISO-8601 UTC.

### Reviews

#### `GET /v1/courses/{course:slug}/reviews`

**Purpose:** Public list of recent reviews for a course (newest first), for the course page and the landing `testimonials` section.
**Auth:** 🔓 Public
**Middleware:** `tenant`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override) | `mrkhaled` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Notes |
|---|---|---|---|
| `course` | path | yes | Course **slug** (route-model bound). |
| `page` | query | no | Page number; 20 per page. |

**Request body:** None

**Response 200** — `ReviewResource` collection, paginated (20/page):

```json
{
  "data": [
    {
      "id": 41,
      "student_name": "أحمد علي",
      "course_title": "فيزياء الصف الثالث الثانوي",
      "rating": 5,
      "comment": "شرح ممتاز وواضح جدًا.",
      "created_at": "2026-07-10T09:14:22+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 47
  }
}
```

**Errors:** `404` — slug resolves to no course in this tenant.

---

#### `POST /v1/courses/{course:slug}/reviews`

**Purpose:** A student with access to the course creates **or updates** (upsert) their single review. Keyed on `course_id` + `user_id`; a repeat submission overwrites the previous rating/comment.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path params**

| Param | In | Required | Notes |
|---|---|---|---|
| `course` | path | yes | Course **slug**. |

**Request body**

```json
{
  "rating": 5,
  "comment": "شرح ممتاز وواضح جدًا."
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `rating` | integer | yes | `min:1`, `max:5` |
| `comment` | string | no | nullable, `max:2000` |

**Response 201** — the upserted review as a `ReviewResource` (`{ "data": { ... } }`); shape identical to the list item above. Note: even an update returns **201**.

**Errors:**
- `403` — student lacks access to the course (`EnrollmentService::hasAccess` false): message `Enroll in this course before reviewing it.`
- `422` — validation (missing/out-of-range `rating`, over-length `comment`).
- `401` / `403` — unauthenticated, or member not `active`.

---

### Progress

#### `POST /v1/lessons/{lesson}/progress`

**Purpose:** Report watch progress for a lesson. Persists the **furthest** watch percentage/seconds (never regresses), updates `last_position_sec`, increments `sessions_count`, and on first crossing of 95% marks the lesson complete and awards `lesson_points` (default 5, reason `lesson.completed`, idempotent).
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Path params**

| Param | In | Required | Notes |
|---|---|---|---|
| `lesson` | path | yes | Lesson **id** (route-model bound). |

**Request body**

```json
{
  "watch_percent": 65,
  "watch_seconds": 780,
  "last_position_sec": 780
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `watch_percent` | integer | yes | `min:0`, `max:100` (stored as the max seen so far) |
| `watch_seconds` | integer | no | nullable, `min:0` (stored as max seen) |
| `last_position_sec` | integer | no | nullable, `min:0` (last reported position) |

**Response 200**

```json
{
  "data": {
    "watch_percent": 65,
    "last_position_sec": 780,
    "completed": false
  }
}
```

**Errors:**
- `403` — no access to the lesson: message `You do not have access to this lesson.` (bypassed when the lesson `is_free_preview`).
- `422` — validation.
- `401` / `403` — unauthenticated / not `active`.

---

#### `GET /v1/me/activity`

**Purpose:** The student's recent lesson activity (up to 50 rows, most-recently-updated first) — a raw progress feed.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "lesson_id": 210,
      "lesson_title": "قوانين نيوتن",
      "watch_percent": 100,
      "last_position_sec": 902,
      "completed": true
    },
    {
      "lesson_id": 211,
      "lesson_title": "الشغل والطاقة",
      "watch_percent": 65,
      "last_position_sec": 780,
      "completed": false
    }
  ]
}
```

Note: plain `{ "data": [...] }` (no pagination meta).

---

#### `GET /v1/me/resume`

**Purpose:** "Continue watching" — lessons **started but not finished** (`completed_at` null and `watch_percent > 0`), newest first, up to 20. Each item carries `course_id` so the SPA can deep-link.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "lesson_id": 211,
      "lesson_title": "الشغل والطاقة",
      "course_id": 34,
      "watch_percent": 65,
      "last_position_sec": 780
    }
  ]
}
```

---

### Favorites

#### `GET /v1/me/favorites`

**Purpose:** The student's saved-courses shortlist (newest first).
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "uuid": "7b9d1f2a-4c8e-4a11-9f3b-1e2d3c4b5a60",
      "title": "فيزياء الصف الثالث الثانوي",
      "slug": "physics-g3",
      "cover_url": "https://cdn.elameed.app/courses/34/cover.jpg"
    }
  ]
}
```

---

#### `POST /v1/me/favorites`

**Purpose:** Add a course to favorites. Idempotent (`firstOrCreate`) — favoriting twice is a no-op that still returns success.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Request body**

```json
{
  "course": "7b9d1f2a-4c8e-4a11-9f3b-1e2d3c4b5a60"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `course` | string (uuid) | yes | Course **uuid**; must resolve to an existing course. |

**Response 201**

```json
{ "data": { "favorited": true } }
```

**Errors:**
- `422` — `course` uuid does not resolve (`ValidationException`, message `Course not found.`). There is **no 409** on re-favorite — the operation is idempotent and returns 201.
- `401` / `403` — unauthenticated / not `active`.

---

#### `DELETE /v1/me/favorites/{course:uuid}`

**Purpose:** Remove a course from favorites. Idempotent — deleting a non-favorite still returns success.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path params**

| Param | In | Required | Notes |
|---|---|---|---|
| `course` | path | yes | Course **uuid** (route-model bound). |

**Request body:** None

**Response 200**

```json
{ "data": { "favorited": false } }
```

**Errors:** `404` — uuid resolves to no course in this tenant.

---

### Gamification · Student

#### `GET /v1/me/points`

**Purpose:** The student's total points plus their 20 most-recent ledger entries.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": {
    "total": 145,
    "recent": [
      { "points": 5, "reason": "lesson.completed", "created_at": "2026-07-14T18:02:41+00:00" },
      { "points": 20, "reason": "exam.passed", "created_at": "2026-07-13T11:20:07+00:00" }
    ]
  }
}
```

---

#### `GET /v1/me/badges`

**Purpose:** Badges the student has earned, with award timestamps.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "name": "Fast Learner",
      "description": "Awarded for reaching 100 points.",
      "icon": "trophy",
      "awarded_at": "2026-07-13T11:20:07+00:00"
    }
  ]
}
```

---

#### `GET /v1/leaderboard`

**Purpose:** Top 20 students by total points for the tenant. Honors the teacher's `hide_ranking` toggle — when hidden, entries are empty and `hidden: true`.
**Auth:** 👤 Authenticated
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200** (visible)

```json
{
  "data": {
    "hidden": false,
    "entries": [
      { "rank": 1, "name": "أحمد علي", "points": 320 },
      { "rank": 2, "name": "سارة محمد", "points": 275 }
    ]
  }
}
```

**Response 200** (hidden by teacher)

```json
{ "data": { "hidden": true, "entries": [] } }
```

---

### Gamification · Teacher

All teacher endpoints require the token holder to be an `active` member with the **teacher** role in the current tenant.

#### `GET /v1/teacher/badges`

**Purpose:** List the teacher's badge catalog, ordered by `points_threshold`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "id": 3,
      "name": "Fast Learner",
      "description": "Awarded for completing 10 lessons.",
      "icon": "trophy",
      "points_threshold": 100
    }
  ]
}
```

---

#### `POST /v1/teacher/badges`

**Purpose:** Create a badge. A non-null `points_threshold` makes it auto-awarded when a student's total crosses it.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Request body**

```json
{
  "name": "Fast Learner",
  "description": "Awarded for completing 10 lessons.",
  "icon": "trophy",
  "points_threshold": 100
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | `max:255` |
| `description` | string | no | nullable, `max:500` |
| `icon` | string | no | nullable, `max:255` |
| `points_threshold` | integer | no | nullable, `min:1` |

**Response 201**

```json
{ "data": { "id": 3, "name": "Fast Learner" } }
```

**Errors:** `422` — validation; `403` — not a teacher.

---

#### `DELETE /v1/teacher/badges/{badge}`

**Purpose:** Delete a badge from the catalog.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path params**

| Param | In | Required | Notes |
|---|---|---|---|
| `badge` | path | yes | Badge **id** (route-model bound). |

**Request body:** None

**Response 204** — no content.

**Errors:** `404` — badge id not found in this tenant; `403` — not a teacher.

---

#### `GET /v1/teacher/gamification`

**Purpose:** Read the tenant's gamification settings (currently just the leaderboard visibility toggle).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{ "data": { "hide_ranking": false } }
```

---

#### `PUT /v1/teacher/gamification`

**Purpose:** Toggle the student leaderboard visibility (`teacher_profiles.hide_ranking`).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| Authorization | yes | `Bearer 12\|abc...` |
| Content-Type | yes | `application/json` |
| Accept | yes | `application/json` |

**Request body**

```json
{ "hide_ranking": true }
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `hide_ranking` | boolean | yes | required |

**Response 200**

```json
{ "data": { "hide_ranking": true } }
```

**Errors:** `422` — missing/non-boolean `hide_ranking`; `403` — not a teacher.
