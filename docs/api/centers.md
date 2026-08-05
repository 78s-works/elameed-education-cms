# Centers Module

> The Centers module (M12) covers a teacher academy's **physical learning-center branches** and everything that flows through them: **activation / recharge codes** (printable one-time codes a student redeems for wallet credit or course access), **attendance** marking, **paper (in-center) exam-grade entry** (VD R12 — a center helper types an offline exam score onto a student, which then shows in the student's results and to the parent), and an **offline sync** ingest so a center's on-site app can flush queued events when it regains connectivity. It also owns two student-facing endpoints, `POST /codes/redeem` and `GET /me/center-exam-grades`. Everything here is tenant-scoped (`BelongsToTenant` + RLS): centers, codes, attendance, and grade rows all carry a `tenant_id` and never leak across academies. Redemption is atomic and one-time — the same code can never credit a wallet or grant a course twice, whether a student double-taps or an offline device re-syncs the same event.

## Models

- **`Center`** — A physical teaching branch. Tenant-scoped, `HasUuids`, route key `uuid`. Fillable: `name`, `address`, `phone`, `is_active` (bool). `hasMany` attendance records.
- **`ActivationCode`** — A one-time recharge/activation code. Tenant-scoped, `HasUuids`, route key `uuid`. Fillable: `code` (the redeemable string), `type` (`CodeType`), `amount_minor` (int, wallet codes), `course_id` (course codes), `center_id` (optional origin branch), `batch` (optional label), `status` (`CodeStatus`), `redeemed_by`, `redeemed_at`, `expires_at`. `isRedeemable()` = status is `active` **and** (`expires_at` is null or in the future). `belongsTo` Course.
- **`AttendanceRecord`** — One student's attendance at a center on one day. Tenant-scoped (integer `id` key — **no** uuid). Fillable: `center_id`, `user_id`, `course_id`, `attended_on` (date), `status`, `marked_by` (teacher user id), `source` (`online` \| `offline`), `external_ref` (client idempotency key from sync), `note`. `belongsTo` Center; `student()` → `User` on `user_id`. A DB unique key on (`tenant_id`, `center_id`, `user_id`, `attended_on`) enforces one record per student/center/day.
- **`CenterExamGrade`** (VD R12, doc 13 P15) — A paper (offline, in-center) exam score against a student. Tenant-scoped **and year-scoped** (`BelongsToTenant` + `BelongsToAcademicYear`), `HasUuids`, route key `uuid`. Fillable: `center_id`, `student_user_id`, `title` (paper-exam label), `total_marks` (decimal), `score` (decimal), `sat_on` (date), `entered_by` (staff user id, nullable), `note`. Casts `total_marks`/`score` → `decimal:2`, `sat_on` → `date`. Relations: `center()`, `student()` → `User` on `student_user_id`, `enteredBy()` → `User`. Lightweight by design (decision D13-9) — no questions/attempts; just the typed result. Index `(tenant_id, student_user_id)`.

### Services (behavior worth knowing)

- **`CodeRedemptionService`** — Atomic redemption. Locks the code row (`lockForUpdate`), validates it is redeemable, then either posts a double-entry ledger transaction (student wallet **credit** balanced by teacher-earnings **debit**, keyed `code:{uuid}` as an idempotency backstop) for wallet codes, or calls `EnrollmentService::grantCourse(... EnrollmentSource::Code)` for course codes. Finally flips the code to `redeemed` with `redeemed_by`/`redeemed_at`. All-or-nothing in one transaction.
- **`CenterSyncService`** — Applies a batch of offline events idempotently and returns a per-item result (`applied` \| `duplicate` \| `failed`). Attendance is deduped on `external_ref` (and the one-per-day unique key as a second guard); redemptions rely on the code's one-time status, and a re-sent redeem by the same student who already redeemed the code is reported as `duplicate` rather than an error.

## Code types / states

**`CodeType`** (what redeeming grants):

| Value | Meaning |
|---|---|
| `wallet` | Credits the student's wallet by `amount_minor`. |
| `course` | Enrolls the student in `course_id`. |

**`CodeStatus`** (lifecycle):

| Value | Meaning |
|---|---|
| `active` | Redeemable (subject to `expires_at`). |
| `redeemed` | Already used — one-time, terminal. |
| `disabled` | Manually revoked by the teacher; not redeemable. |

Money is always **integer minor units** (`amount_minor`); the wallet's currency is the tenant's. Timestamps are ISO-8601 UTC.

---

## Endpoints

All routes are under the base prefix `/api/v1` and run through the `tenant` middleware group (Host header resolution, or `X-Tenant: <slug>` dev override). Centers and codes bind by `uuid`; unknown or cross-tenant identifiers resolve to **404**.

### Student · Redeem

#### `POST /codes/redeem`

**Purpose:** A logged-in student redeems an activation/recharge code — crediting their wallet or enrolling them in a course, depending on the code type.
**Auth:** 👤 Authenticated (student)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | optional (dev override only) | `mrkhaled` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <token>` |
| Content-Type | yes | `application/json` |

**Path / Query params:** None

**Request body**

```json
{
  "code": "ABC123XYZ789"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `code` | string | yes | `max:40` |

**Response 200** — wallet code (credited)

```json
{
  "data": {
    "code": "ABC123XYZ789",
    "type": "wallet",
    "amount_minor": 10000
  }
}
```

**Response 200** — course code (enrolled)

```json
{
  "data": {
    "code": "ABC123XYZ789",
    "type": "course",
    "course_id": 12
  }
}
```

**Errors:** `422` — invalid/unknown code (`"Invalid code."`), expired code (`"This code has expired."`), already-used or disabled code (`"This code has already been used."`), or the course for a course-code no longer exists (`"The course for this code is no longer available."`); all returned as validation errors on the `code` field. `422` also for a missing/oversized `code`.

---

### Teacher · Centers

All teacher endpoints share: **Auth** 🧑‍🏫 role:teacher; **Middleware** `tenant`, `auth:sanctum`, `active`, `role:teacher`. Headers are the same as above (Bearer required; `Content-Type: application/json` on requests with a JSON body).

#### `GET /teacher/centers`

**Purpose:** List all of this academy's branches (newest first). **Not paginated** — returns the full set.
**Path / Query params:** None
**Request body:** None

**Response 200**

```json
{
  "data": [
    {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "name": "Downtown Branch",
      "address": "5 Talaat Harb St, Cairo",
      "phone": "+20221234567",
      "is_active": true
    }
  ]
}
```

#### `POST /teacher/centers`

**Purpose:** Create a branch.
**Request body**

```json
{
  "name": "Downtown Branch",
  "address": "5 Talaat Harb St, Cairo",
  "phone": "+20221234567",
  "is_active": true
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string | yes | `max:255` |
| `address` | string | no | nullable, `max:255` |
| `phone` | string | no | nullable, `max:30` |
| `is_active` | bool | no | boolean |

**Response 201** — a single `CenterResource` (same shape as a list item above).

**Errors:** `422` (validation).

#### `PUT /teacher/centers/{center:uuid}`

**Purpose:** Update a branch. Same body/rules as create.
**Path params:** `center` — center `uuid`.
**Response 200** — the updated `CenterResource`.
**Errors:** `404` unknown/cross-tenant center; `422` validation.

#### `DELETE /teacher/centers/{center:uuid}`

**Purpose:** Delete a branch.
**Path params:** `center` — center `uuid`.
**Response 204** — no content.
**Errors:** `404` unknown/cross-tenant center.

---

### Teacher · Sync

#### `POST /teacher/centers/sync`

**Purpose:** The offline center app flushes its queued **attendance** and **redeem** events in one batch. Applied idempotently; returns a per-item result so the device can reconcile its outbox.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Path / Query params:** None

**Request body** — an `events` array (1–500 items). Each event carries a client `external_ref` used for idempotency. Validation is deliberately loose; per-event specifics are resolved server-side.

```json
{
  "events": [
    {
      "kind": "attendance",
      "external_ref": "device-001-evt-1001",
      "center_uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "student_uuid": "6f1b0a2e-8c3d-4a5b-9e7f-1a2b3c4d5e6f",
      "attended_on": "2026-07-09",
      "status": "present"
    },
    {
      "kind": "redeem",
      "external_ref": "device-001-evt-1002",
      "student_phone": "+201112223334",
      "code": "ABC123XYZ789"
    }
  ]
}
```

| Field | Type | Required | Rules / Notes |
|---|---|---|---|
| `events` | array | yes | `min:1`, `max:500` |
| `events.*.kind` | string | yes | `attendance` \| `redeem` |
| `events.*.external_ref` | string | yes | `max:100` — client idempotency key |
| `events.*.center_uuid` | string | no | attendance: which branch |
| `events.*.student_uuid` | string | no | student lookup (tried first) |
| `events.*.student_phone` | string | no | student lookup (fallback if uuid misses) |
| `events.*.code` | string | no | redeem: the code string, `max:40` |
| `events.*.attended_on` | date | no | attendance day (defaults to today) |
| `events.*.status` | string | no | `present` \| `absent` |

Student resolution: `student_uuid` is tried first, then `student_phone`; the resolved user must be a `student` member of this tenant or the event fails.

**Response 200** — one result object per input event, in order:

```json
{
  "data": [
    {
      "external_ref": "device-001-evt-1001",
      "kind": "attendance",
      "status": "applied"
    },
    {
      "external_ref": "device-001-evt-1002",
      "kind": "redeem",
      "status": "applied",
      "grant": {
        "code": "ABC123XYZ789",
        "type": "wallet",
        "amount_minor": 10000
      }
    }
  ]
}
```

Per-item `status` is `applied`, `duplicate` (already synced / same student already redeemed), or `failed` (with a `message`, e.g. `"Unknown center."`, `"Unknown student."`, `"Missing code."`, or the redemption error). The endpoint itself returns **200** even when individual items fail — the caller inspects each result.

**Errors:** `422` — malformed batch (empty, over 500, bad `kind`, missing `external_ref`). Individual event problems surface as `failed` items, not HTTP errors.

---

### Teacher · Attendance

#### `GET /teacher/centers/{center:uuid}/attendance`

**Purpose:** List a branch's attendance records (most recent day first). **Paginated**, 50 per page.
**Auth:** 🧑‍🏫 role:teacher
**Path params:** `center` — center `uuid`.

**Response 200**

```json
{
  "data": [
    {
      "id": 5012,
      "center_id": 3,
      "student": {
        "uuid": "6f1b0a2e-8c3d-4a5b-9e7f-1a2b3c4d5e6f",
        "name": "Sara Ahmed",
        "phone": "+201112223334"
      },
      "course_id": 12,
      "attended_on": "2026-07-09",
      "status": "present",
      "source": "online"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://mrkhaled.elameed.app/api/v1/teacher/centers/{uuid}/attendance",
    "per_page": 50,
    "to": 1,
    "total": 1
  }
}
```

**Errors:** `404` unknown/cross-tenant center.

#### `POST /teacher/centers/{center:uuid}/attendance`

**Purpose:** Bulk-mark a list of students for the branch on a given day (upsert — re-marking the same student/day updates the row). Unknown uuids or non-student members are silently skipped and reported back.
**Auth:** 🧑‍🏫 role:teacher
**Path params:** `center` — center `uuid`.

**Request body**

```json
{
  "attended_on": "2026-07-09",
  "status": "present",
  "course_id": 12,
  "students": ["6f1b0a2e-8c3d-4a5b-9e7f-1a2b3c4d5e6f"]
}
```

| Field | Type | Required | Rules / Notes |
|---|---|---|---|
| `attended_on` | date | no | defaults to today |
| `status` | string | no | `present` \| `absent` (default `present`) |
| `course_id` | int | no | optional course context |
| `students` | array | yes | `min:1`, `max:500` |
| `students.*` | string | yes | student `uuid` |

Records created here get `source: "online"` and `marked_by` = the acting teacher.

**Response 200**

```json
{
  "data": {
    "marked": 1,
    "skipped": ["11111111-2222-3333-4444-555555555555"]
  }
}
```

`marked` is the count upserted; `skipped` lists the uuids that were not resolvable students of this tenant.

**Errors:** `404` unknown/cross-tenant center; `422` validation (empty/oversized `students`, bad `status`).

---

### Teacher · Activation codes

#### `GET /teacher/codes`

**Purpose:** List generated codes (newest first). **Paginated**, 50 per page.
**Auth:** 🧑‍🏫 role:teacher

**Query params** (all optional)

| Param | Example | Notes |
|---|---|---|
| `filter[status]` | `active` | `active` \| `redeemed` \| `disabled` |
| `filter[type]` | `wallet` | `wallet` \| `course` |
| `filter[batch]` | `SUMMER-2026` | exact batch label |

**Response 200**

```json
{
  "data": [
    {
      "uuid": "3a1c9e77-4b2d-4c8e-9f10-77aa22bb33cc",
      "code": "ABC123XYZ789",
      "type": "wallet",
      "amount_minor": 10000,
      "course_id": null,
      "batch": "SUMMER-2026",
      "status": "active",
      "redeemed_by": null,
      "redeemed_at": null,
      "expires_at": "2026-12-31T23:59:59+00:00"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://mrkhaled.elameed.app/api/v1/teacher/codes",
    "per_page": 50,
    "to": 1,
    "total": 1
  }
}
```

#### `POST /teacher/codes/batch`

**Purpose:** Generate N codes at once (unique random 12-char uppercase strings, all `active`). `wallet` codes require `amount_minor`; `course` codes require a `course_id` owned by this tenant.
**Auth:** 🧑‍🏫 role:teacher

**Request body**

```json
{
  "type": "wallet",
  "count": 50,
  "amount_minor": 10000,
  "center_id": 3,
  "batch": "SUMMER-2026",
  "expires_at": "2026-12-31T23:59:59Z"
}
```

| Field | Type | Required | Rules / Notes |
|---|---|---|---|
| `type` | string | yes | `wallet` \| `course` |
| `count` | int | yes | `min:1`, `max:1000` |
| `amount_minor` | int | if `type=wallet` | `min:1` (ignored for course codes) |
| `course_id` | int | if `type=course` | must `exist` in `courses` for this tenant (ignored for wallet codes) |
| `center_id` | int | no | must `exist` in `centers` for this tenant |
| `batch` | string | no | `max:100` — grouping label |
| `expires_at` | date | no | `after:now` |

> Note: `course_id`/`center_id` here are **integer ids** (validated against the DB), not uuids.

**Response 201** — an array of the created `ActivationCodeResource` objects (same shape as a list item; **no** pagination meta since it is a freshly created set).

```json
{
  "data": [
    {
      "uuid": "3a1c9e77-4b2d-4c8e-9f10-77aa22bb33cc",
      "code": "K7QF2M9XZ1AB",
      "type": "wallet",
      "amount_minor": 10000,
      "course_id": null,
      "batch": "SUMMER-2026",
      "status": "active",
      "redeemed_by": null,
      "redeemed_at": null,
      "expires_at": "2026-12-31T23:59:59+00:00"
    }
  ]
}
```

**Errors:** `422` — e.g. `amount_minor` missing for a wallet batch, `course_id` missing/not owned for a course batch, `count` out of range, `expires_at` not in the future.

#### `POST /teacher/codes/{code:uuid}/disable`

**Purpose:** Revoke a code so it can no longer be redeemed. No-op if the code is not currently `active` (already redeemed/disabled codes are returned unchanged).
**Auth:** 🧑‍🏫 role:teacher
**Path params:** `code` — code `uuid`.
**Request body:** None

**Response 200** — the `ActivationCodeResource` with `status` now `disabled` (or unchanged if it was not active).

**Errors:** `404` unknown/cross-tenant code.

---

### Student · Center exam grades

#### `GET /me/center-exam-grades`

**Purpose:** The logged-in student's own paper (in-center) exam scores, newest exam-date first. Returns grades across **every academic year** (no `X-Academic-Year` header needed) — still tenant-scoped.
**Auth:** 👤 Authenticated (any member; a student sees only their own rows)
**Middleware:** `tenant`, `auth:sanctum`, `active`
**Path / Query params:** None
**Request body:** None

**Response 200** — a `CenterExamGradeResource` collection.

```json
{
  "data": [
    {
      "id": "b7f3d0e2-1a44-4c9e-8f21-0c2d5e7a9b10",
      "title": "Monthly test — Algebra",
      "total_marks": 40,
      "score": 33.5,
      "sat_on": "2026-07-15",
      "note": null,
      "center": { "uuid": "9d2a7c14-...", "name": "Downtown Branch" },
      "student": { "uuid": "6f1b0a2e-...", "name": "Sara Ahmed", "phone": "+201112223334" },
      "entered_by": "Assistant Omar",
      "created_at": "2026-07-16T09:12:00+00:00"
    }
  ]
}
```

> The same rows also surface in the **parent portal** read `GET /parent/children/{student:uuid}/results` (M13), merged with online exam attempts into one list keyed by a `source` discriminator (`online_exam` \| `center_exam`).

---

### Teacher · Center exam grades (VD R12)

Record/list/edit paper exam scores. **Auth** 🧑‍🏫 `role:teacher,assistant` + **`permission:centers`** (the center-helper surface, beside attendance/codes; a teacher passes implicitly). These routes are **year-scoped**: they run inside the `academic-year` middleware, so every request carries **`X-Academic-Year: <uuid>`**, a new grade is stamped with that year automatically, and `{grade}` binds by `uuid` **within the active year** (a grade from another year or tenant → 404).

**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:centers`, `academic-year`.

#### `GET /teacher/center-exam-grades`

**Purpose:** List the active year's grades (newest exam-date first). **Paginated**, 50 per page.

**Query params** (all optional)

| Param | Example | Notes |
|---|---|---|
| `student` | `6f1b0a2e-...` | filter to one student's uuid |
| `center` | `9d2a7c14-...` | filter to one branch's uuid |

**Response 200** — a paginated `CenterExamGradeResource` collection (row shape as in the student endpoint above; `meta`/`links` as with the other paginated lists).

#### `POST /teacher/center-exam-grades`

**Purpose:** Record a paper exam score for a student.

**Request body**

```json
{
  "title": "Monthly test — Algebra",
  "center": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
  "student": "6f1b0a2e-8c3d-4a5b-9e7f-1a2b3c4d5e6f",
  "total_marks": 40,
  "score": 33.5,
  "sat_on": "2026-07-15",
  "note": "Strong on factorization"
}
```

| Field | Type | Required | Rules / Notes |
|---|---|---|---|
| `title` | string | yes | `max:255` |
| `center` | string | yes | center `uuid` — must resolve in this tenant |
| `student` | string | yes | student `uuid` — must be an active `student` member of this tenant |
| `total_marks` | numeric | yes | `min:0` |
| `score` | numeric | yes | `min:0`, `lte:total_marks` |
| `sat_on` | date | yes | exam date |
| `note` | string | no | nullable, `max:1000` |

`academic_year_id` is taken from the `X-Academic-Year` context (not the body); `entered_by` is the acting staff user.

**Response 201** — the created `CenterExamGradeResource`.

**Errors:** `422` — validation (`score > total_marks`, negatives, unknown/foreign `center`, `student` not a student of the tenant, missing fields). `422` from the `academic-year` middleware if `X-Academic-Year` is absent; `403` if the year uuid is unknown/foreign.

#### `PUT /teacher/center-exam-grades/{grade:uuid}`

**Purpose:** Update a grade. Same body/rules as create.
**Path params:** `grade` — grade `uuid` (resolved within the active year).
**Response 200** — the updated `CenterExamGradeResource`.
**Errors:** `404` unknown / cross-tenant / other-year grade; `422` validation.

#### `DELETE /teacher/center-exam-grades/{grade:uuid}`

**Purpose:** Remove a grade.
**Path params:** `grade` — grade `uuid` (resolved within the active year).
**Response 204** — no content.
**Errors:** `404` unknown / cross-tenant / other-year grade.
