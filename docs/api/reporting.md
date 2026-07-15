# Reporting Module

> The Reporting module exposes read-only summaries and the immutable activity trail that sit on top of other modules' data. It has three surfaces: a **student** "my courses" view with per-course progress, **teacher report** aggregates (sales/revenue and student/course counts for the teacher's own academy), and the **audit log** (M18) — an append-only record of privileged actions. The audit log is read at two scopes by two methods of the same controller: `teacher` (own-tenant, wired here) and `admin` (cross-tenant, documented in the Platform Admin module). All non-admin endpoints run inside the `tenant` group; the teacher report/audit endpoints additionally require `role:teacher`.

Money is reported as an integer in minor units (`*_minor`); the platform base currency is EGP. Timestamps are ISO-8601 UTC.

## Models

- **`AuditLog`** (`audit_logs`) — Append-only audit record. **Not** tenant-scoped at the model level: `tenant_id` is nullable (cross-tenant admin actions), so reads filter `tenant_id` explicitly. `UPDATED_AT` is disabled (`const UPDATED_AT = null`) — rows are write-once with only a `created_at`. Fillable: `tenant_id`, `actor_user_id`, `action`, `subject_type`, `subject_id`, `meta` (JSON, cast to array), `ip`. Relation: `actor()` -> `App\Models\User` (via `actor_user_id`).

> The student and teacher report endpoints read from **other modules'** models (`Catalog\Course`, `Commerce\Enrollment` / `Order`, `Engagement\LessonProgress`, `Wallet\LedgerEntry`, `Identity\TenantUser`); Reporting owns only `AuditLog`.

---

## Endpoints

### Student

#### `GET /v1/me/courses`

**Purpose:** Return the authenticated student's accessible courses (from access-granting enrollments) in the current tenant, each with a lesson-progress summary. Not paginated — returns a plain `data` array.

**Auth:** 👤 Authenticated student (any active member)
**Middleware:** `tenant` group -> `auth:sanctum` -> `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "uuid": "0b8f9c2e-1d34-4a76-9c0e-77e2a1b3c4d5",
      "title": "الفيزياء - الصف الثالث الثانوي",
      "slug": "physics-g3",
      "cover_url": "https://cdn.elameed.app/courses/12/cover.jpg",
      "lessons_total": 24,
      "lessons_completed": 9,
      "watch_precent": 37,
      "progress_percent": 38
    }
  ]
}
```

Notes:
- Courses are derived from `Enrollment` rows for the user that `grantsAccess()` and have a non-null `course_id`.
- `lessons_total` is the course's lesson count; `lessons_completed` counts `LessonProgress` rows with a non-null `completed_at`.
- `progress_percent` is `round(completed / total * 100)` (integer), or `0` when the course has no lessons.
- `watch_precent` is spelled with the typo as returned by the API (the JSON key is literally `watch_precent`); it reflects a single `LessonProgress.watch_percent` value and is `0` when no progress row exists.

**Errors:**
- `401 unauthenticated` — missing/invalid bearer token.
- `403` — not an active member of the resolved tenant.
- `403 / 404` — unregistered or non-active host (domain gate).

---

### Teacher · Reports

#### `GET /v1/teacher/reports/sales`

**Purpose:** Revenue snapshot for the teacher's own academy: total teacher earnings, gross paid volume, and count of paid orders. All figures tenant-scoped to the caller's academy.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None (no date-range filter in Phase 1 — the report is all-time).

**Request body:** None

**Response 200**

```json
{
  "data": {
    "earnings_minor": 4820000,
    "gross_minor": 5100000,
    "orders_paid": 128
  }
}
```

Notes:
- `earnings_minor` = sum of `LedgerEntry` credits on the `teacher_earnings` account (tenant-scoped via `BelongsToTenant`).
- `gross_minor` = sum of `total_minor` over orders with status `paid`.
- `orders_paid` = count of `paid` orders.
- Amounts are integer minor units (EGP); no `currency` field is emitted by this endpoint.

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant (`role:teacher`).
- `401 unauthenticated` — missing/invalid bearer token.

---

#### `GET /v1/teacher/reports/students`

**Purpose:** Headline counts for the teacher's academy: number of active students and number of courses.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params:** None

**Request body:** None

**Response 200**

```json
{
  "data": {
    "students": 342,
    "courses": 11
  }
}
```

Notes:
- `students` counts `TenantUser` rows for the current `tenant_id` with `role = student` and `status = active` (the `tenant_user` table is global, so it is filtered by `tenant_id` explicitly).
- `courses` is the tenant-scoped `Course` count.

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant.
- `401 unauthenticated` — missing/invalid bearer token.

---

### Teacher · Audit log

#### `GET /v1/teacher/audit-logs`

**Purpose:** Read the teacher's own-academy audit trail (M18) — an append-only list of privileged actions, newest first. Scope is hard-pinned to the caller's tenant; a teacher can never see another academy's entries.

**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant` group -> `auth:sanctum` -> `active` -> `role:teacher`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Authorization | yes | `Bearer 42\|abc...` |
| Accept | yes | `application/json` |

**Path / Query params**

| Param | In | Required | Description |
|---|---|---|---|
| `page` | query | no | Page number (default 1). Page size fixed at 50. |

> Note: the `?tenant=` filter accepted by the admin variant is **ignored here** — the teacher scope is forced to the resolved tenant.

**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "action": "student.updated",
      "actor": "مستر خالد",
      "subject_type": "student",
      "subject_id": 8817,
      "meta": {
        "changes": { "status": "active" }
      },
      "ip": "197.45.12.9",
      "created_at": "2026-07-15T08:22:41+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 6,
    "total": 273
  }
}
```

Notes:
- `actor` is the actor user's `name` (string) or `null` when unavailable — it is not a nested object.
- per-row `meta` is the action's arbitrary JSON context; `ip` is the source IP recorded at write time.
- The list `meta` block here is the controller's **custom** paginator shape — only `current_page`, `last_page`, `total` (no `per_page` / `from` / `to`).

**Errors:**
- `403` — caller lacks the `teacher` role in the current tenant.
- `401 unauthenticated` — missing/invalid bearer token.
