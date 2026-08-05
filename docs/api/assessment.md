# Assessment Module

> The Assessment module (M08) covers **exams, quizzes and assignments** — the same `Exam` model backs all three via a `type`/`mode` pair. It has two surfaces. **Students** discover published, in-window exams for courses they are enrolled in, start (or resume) a timed attempt, submit answers, and read their result. **Teachers** author exams under a course, manage the question bank per exam (including the hidden answer key), list submissions, and hand-grade the subjective questions. Objective questions (MCQ, true/false) are graded automatically on submit by `GradingService`; subjective ones (short answer, essay, file) flip the attempt into a `needs_manual_grade` state until a teacher scores them. A passing attempt awards gamification points once (idempotent per exam). The answer key is never leaked to students during an attempt — it is only ever echoed back in a post-submission review, and only when the teacher enabled `show_answers`.

## Models

- **`Exam`** — An exam/assignment under a course (`course_id`, optional `lesson_id`). Tenant-scoped (`BelongsToTenant`), `HasUuids` (route key is `uuid`), soft-deletes (delete keeps attempt history). Config columns: `title`, `type`, `mode`, `pass_percent`, `duration_min`, `attempts_allowed` (0 = unlimited), `question_order` (`fixed`|`random`), `scoring` (`best`|`last`|`first`), `starts_at`, `ends_at`, `result_visibility`, `show_answers`, `depends_on_exam_id` (prerequisite exam), `is_published`. `isOpen()` = published AND within the optional `starts_at`/`ends_at` window.
- **`Question`** — A question attached to an exam (`exam_id`) — or a reusable bank item when `exam_id` is null (bank is not exposed by these routes). Tenant-scoped. Casts `options`, `correct`, `book_ref` to arrays; `points` to int. **`correct` is in the model's `$hidden`** so it never serialises by default; the teacher `QuestionResource` re-adds it explicitly. `body` may be null for bubble-sheet MCQ. `book_ref` holds `{ book, page, qno }` for printed-book (bubble-sheet) questions.
- **`ExamAttempt`** — One student attempt (`exam_id`, `user_id`, `attempt_number`). Tenant-scoped, bound in routes by `id`. Holds `started_at`, `submitted_at`, `score`, `max_score`, `status`, `answers` (JSON map `question_id => { answer, awarded, is_correct }`), `needs_manual_grade`, and (for graded upload homework) `feedback` (text) + `corrected_file` (JSON `{ path, name, size, mime }` pointer into the private `assignments/` disk). No UUID — attempt ids are only reachable through an exam the student/teacher already owns.

## Question types & attempt states

**`QuestionType`** (`type`): `mcq`, `true_false`, `short`, `essay`, `file`.
- Auto-graded on submit (`isAutoGraded()`): `mcq`, `true_false`.
- Manual (teacher-graded): `short`, `essay`, `file` — these set `needs_manual_grade = true`.

**`ExamType`** (`type`): `exam`, `assignment` (defaults to `exam`).

**`ExamMode`** (`mode`): `standard` (questions shown on screen), `bubble_sheet` (questions live in a printed book; the app shows only the `book_ref` + choice letters). Defaults to `standard`.

**`AttemptStatus`** (`status`): `in_progress` → `submitted` (awaiting manual grading) → `graded`. A fully auto-graded submission skips straight to `graded`; anything with a subjective question lands in `submitted` until the teacher finishes grading.

**Answer-key protection.** During an attempt, questions are serialised with `PublicQuestionResource`, which omits `correct`. The teacher `QuestionResource` includes `correct`. The only student-facing path that reveals `correct` is the per-question `review` block in a result — gated on both the score being visible (per `result_visibility`) **and** the exam's `show_answers` flag.

**Result visibility** (`result_visibility`, evaluated in `AttemptController::scoreVisible`): score/`review` are always hidden while `in_progress`. Otherwise —
- `immediate` (default): visible as soon as the attempt leaves `in_progress` (so a partial auto-graded score shows even while manual grading is pending).
- `after_close`: visible once the attempt is `graded` **or** the exam's `ends_at` has passed.
- `manual`: visible only once the attempt is `graded`.

**Scoring.** `points` are integer per question. Auto-graded matching is set-equality on normalised values (case-insensitive, booleans normalised to `"true"`/`"false"`), so MCQ supports multiple correct indices; an empty key never matches. `pass_percent` is compared against `score / max_score * 100`. Passing a fully-graded attempt awards `config('gamification.exam_points', 20)` points once per exam (event `exam.passed`).

---

## Endpoints

All routes sit under the `/api/v1` prefix and the `tenant` middleware group (host resolution + RLS binding). Success bodies use `{ "data": ... }`; errors use `{ "error": { "code", "message", "details? } }`. Timestamps are ISO-8601 (`toIso8601String()`, e.g. `2026-09-01T10:15:00+00:00`). **List endpoints here are not paginated** — they return the full array under `data` with no `meta`.

### Student · Attempts

#### `GET /v1/exams`

**Purpose:** List published, currently-in-window exams for every course the student has active access to (enrollment that `grantsAccess`).
**Auth:** 👤 Authenticated (student)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers**

| Header | Required | Example |
|---|---|---|
| Host | yes | `mrkhaled.elameed.app` |
| X-Tenant | dev override only | `mrkhaled` |
| Accept | yes | `application/json` |
| Authorization | yes | `Bearer <token>` |

**Path / Query params:** None

**Request body:** None

**Response 200** — `ExamResource` collection (no `questions_count`, no answer data):

```json
{
  "data": [
    {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "course_id": 12,
      "lesson_id": null,
      "title": "Midterm Exam - Mechanics",
      "type": "exam",
      "mode": "standard",
      "pass_percent": 60,
      "duration_min": 90,
      "attempts_allowed": 2,
      "question_order": "fixed",
      "scoring": "best",
      "starts_at": "2026-09-01T09:00:00+00:00",
      "ends_at": "2026-09-01T12:00:00+00:00",
      "result_visibility": "immediate",
      "show_answers": true,
      "depends_on_exam_id": null,
      "is_published": true
    }
  ]
}
```

**Errors:** `401 unauthenticated`, `403 forbidden` (suspended membership, via `active`).

---

#### `POST /v1/exams/{exam:uuid}/attempts`

**Purpose:** Start a new attempt, or resume the student's existing `in_progress` one. Returns the questions with **no answer key**.
**Auth:** 👤 Authenticated (student)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers:** Host, Accept, `Authorization: Bearer <token>` (as above).

**Path params**

| Param | In | Type | Notes |
|---|---|---|---|
| `exam` | path | uuid | Bound by `uuid`; cross-tenant → 404 |

**Request body:** None

**Response 200** — custom envelope (not `ExamResource`). Questions use `PublicQuestionResource`, so `correct` is absent. Order follows `sort_order` then `id`, or is shuffled when `question_order` is `random`:

```json
{
  "data": {
    "attempt_id": 45,
    "attempt_number": 1,
    "duration_min": 90,
    "questions": [
      { "id": 101, "type": "mcq", "body": "What is the SI unit of force?", "options": ["Joule", "Newton", "Watt", "Pascal"], "points": 2, "book_ref": null },
      { "id": 102, "type": "true_false", "body": "Force equals mass times acceleration.", "options": null, "points": 1, "book_ref": null },
      { "id": 103, "type": "essay", "body": "Explain Newton's second law.", "options": null, "points": 5, "book_ref": null }
    ]
  }
}
```

**Errors:**
- `409` (code `error`, message `"This exam is not open."`) — not published or outside its `starts_at`/`ends_at` window.
- `409` (code `error`, message `"No attempts remaining for this exam."`) — attempts made ≥ `attempts_allowed` (only enforced when `attempts_allowed > 0`; an in-progress attempt is resumed rather than blocked).
- `403 forbidden` (`"You do not have access to this exam."`) — no active enrollment in the exam's course.
- `403 forbidden` (`"Complete the prerequisite exam first."`) — `depends_on_exam_id` set and not yet passed.
- `404 not_found` — unknown/cross-tenant exam.

---

#### `POST /v1/exams/{exam:uuid}/attempts/{attempt}/submit`

**Purpose:** Submit answers. Auto-grades objective questions immediately; if any subjective question exists the attempt becomes `submitted` and awaits manual grading, otherwise `graded`. Awards points if fully graded and passing.
**Auth:** 👤 Authenticated (student, must own the attempt)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers:** Host, Accept, `Content-Type: application/json`, `Authorization: Bearer <token>`.

**Path params**

| Param | In | Type | Notes |
|---|---|---|---|
| `exam` | path | uuid | Bound by `uuid` |
| `attempt` | path | int (id) | Must belong to `exam` and the caller |

**Request body** — `answers` is a map keyed by **question id**; the value shape varies by type:

```json
{
  "answers": {
    "101": 1,
    "102": true,
    "103": "My essay answer text."
  }
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `answers` | object | yes (`present`) | `question_id => answer`. May be `{}`. MCQ = option index or array of indices; `true_false` = boolean; `short`/`essay` = string; `file` = (manual). Missing questions score 0 / stay ungraded. |

**Response 200** — shaped by `result_visibility` + `show_answers`. Base fields are always present; `score`/`max_score`/`passed` appear only when the score is visible; `review` (with the answer key) only when visible **and** `show_answers` is true:

```json
{
  "data": {
    "attempt_id": 45,
    "status": "submitted",
    "needs_manual_grade": true,
    "submitted_at": "2026-09-01T10:15:00+00:00",
    "score": 3,
    "max_score": 8,
    "passed": false,
    "review": [
      { "question_id": 101, "your_answer": 1, "awarded": 2, "is_correct": true, "correct": [1], "points": 2 },
      { "question_id": 102, "your_answer": true, "awarded": 1, "is_correct": true, "correct": [true], "points": 1 },
      { "question_id": 103, "your_answer": "My essay answer text.", "awarded": null, "is_correct": null, "correct": [], "points": 5 }
    ]
  }
}
```

(For a fully auto-graded exam, `status` is `graded` and `needs_manual_grade` is `false`.)

**Errors:**
- `409` (code `error`, `"This attempt has already been submitted."`) — attempt not `in_progress`.
- `404 not_found` — attempt not owned by caller or not on this exam.
- `422 validation_error` — `answers` missing.

---

#### `GET /v1/exams/{exam:uuid}/attempts/{attempt}`

**Purpose:** Read a single attempt result. Same visibility rules as submit.
**Auth:** 👤 Authenticated (student, must own the attempt)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params**

| Param | In | Type | Notes |
|---|---|---|---|
| `exam` | path | uuid | Bound by `uuid` |
| `attempt` | path | int (id) | Must belong to `exam` and the caller |

**Request body:** None

**Response 200** — identical shape to the submit response (`attempt_id`, `status`, `needs_manual_grade`, `submitted_at`, and conditionally `score`/`max_score`/`passed`/`review`). While `in_progress`, only the base fields are returned. Once a teacher attaches them, `feedback` (string) and `corrected_file` (`{ name, size }`) are also included — these surface regardless of score visibility, since they are the teacher's message about the submission. Example when hidden by `result_visibility: manual` before grading:

```json
{
  "data": {
    "attempt_id": 45,
    "status": "submitted",
    "needs_manual_grade": true,
    "submitted_at": "2026-09-01T10:15:00+00:00"
  }
}
```

**Errors:** `404 not_found` (not owned / wrong exam).

---

#### `GET /v1/exams/{exam:uuid}/attempts/{attempt}/corrected-file`

**Purpose:** Download the teacher's corrected/annotated file for the student's **own** attempt (upload homework). Streamed from the private `assignments/` disk; the storage path is never exposed.
**Auth:** 👤 Authenticated (student, must own the attempt)
**Middleware:** `tenant`, `auth:sanctum`, `active`

**Path params:** `exam` (uuid), `attempt` (int id; must belong to `exam` and the caller).

**Response 200** — the file as a binary download (`Content-Disposition: attachment`).
**Errors:** `404 not_found` (not owned / wrong exam / no corrected file attached).

---

### Teacher · Exams

All teacher routes add `role:teacher`. `Exam` and `Course` bind by `uuid`; cross-tenant ids resolve to 404.

#### `GET /v1/teacher/courses/{course:uuid}/exams`

**Purpose:** List all exams of one course (newest first), with a question count.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params**

| Param | In | Type | Notes |
|---|---|---|---|
| `course` | path | uuid | Bound by `uuid` |

**Response 200** — `ExamResource` collection, each with `questions_count`:

```json
{
  "data": [
    {
      "uuid": "9d2a7c14-3b6e-4f0a-8b21-2c9f1d5e7a10",
      "course_id": 12,
      "lesson_id": null,
      "title": "Midterm Exam - Mechanics",
      "type": "exam",
      "mode": "standard",
      "pass_percent": 60,
      "duration_min": 90,
      "attempts_allowed": 2,
      "question_order": "fixed",
      "scoring": "best",
      "starts_at": "2026-09-01T09:00:00+00:00",
      "ends_at": "2026-09-01T12:00:00+00:00",
      "result_visibility": "immediate",
      "show_answers": true,
      "depends_on_exam_id": null,
      "is_published": true,
      "questions_count": 3
    }
  ]
}
```

**Errors:** `403 forbidden` (not a teacher), `404 not_found` (course).

---

#### `POST /v1/teacher/courses/{course:uuid}/exams`

**Purpose:** Create an exam under a course. `tenant_id` is auto-filled.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Content-Type: application/json`, `Authorization: Bearer <token>`.

**Request body** (`ExamRequest`):

```json
{
  "title": "Midterm Exam - Mechanics",
  "lesson_id": null,
  "type": "exam",
  "pass_percent": 60,
  "duration_min": 90,
  "attempts_allowed": 2,
  "question_order": "fixed",
  "scoring": "best",
  "starts_at": "2026-09-01T09:00:00Z",
  "ends_at": "2026-09-01T12:00:00Z",
  "result_visibility": "immediate",
  "show_answers": true,
  "depends_on_exam_id": null,
  "mode": "standard",
  "is_published": true
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `title` | string | yes | max 255 |
| `lesson_id` | int | no | must exist in this tenant |
| `type` | enum | no | `exam` \| `assignment` (default `exam`) |
| `pass_percent` | int | no | 0–100 |
| `duration_min` | int | no | ≥ 1 |
| `attempts_allowed` | int | no | ≥ 0 (0 = unlimited) |
| `question_order` | enum | no | `fixed` \| `random` |
| `scoring` | enum | no | `best` \| `last` \| `first` |
| `starts_at` | date | no | — |
| `ends_at` | date | no | `after_or_equal:starts_at` |
| `result_visibility` | enum | no | `immediate` \| `after_close` \| `manual` |
| `show_answers` | bool | no | — |
| `depends_on_exam_id` | int | no | must exist in this tenant |
| `mode` | enum | no | `standard` \| `bubble_sheet` (default `standard`) |
| `is_published` | bool | no | — |

**Response 201** — `ExamResource` (no `questions_count` on the create response). Same field set as the list example above, minus `questions_count`.

**Errors:** `422 validation_error` (e.g. `ends_at` before `starts_at`, unknown `lesson_id`/`depends_on_exam_id`), `403 forbidden`, `404 not_found` (course).

---

#### `GET /v1/teacher/exams/{exam:uuid}`

**Purpose:** Show one exam with its `questions_count`.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid).

**Response 200** — single `ExamResource` with `questions_count` (same shape as the list item).

**Errors:** `404 not_found`, `403 forbidden`.

---

#### `PUT /v1/teacher/exams/{exam:uuid}`

**Purpose:** Update an exam (same rules and fields as create; all optional except `title`).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Content-Type: application/json`, `Authorization: Bearer <token>`.

**Request body** — subset of `ExamRequest` (see create). Example:

```json
{
  "title": "Midterm Exam - Mechanics (v2)",
  "type": "exam",
  "pass_percent": 65,
  "duration_min": 120,
  "attempts_allowed": 1,
  "is_published": true
}
```

**Response 200** — updated `ExamResource` with `questions_count`.

**Errors:** `422 validation_error`, `404 not_found`, `403 forbidden`.

---

#### `DELETE /v1/teacher/exams/{exam:uuid}`

**Purpose:** Soft-delete the exam (attempt history is preserved).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid).

**Response 204** — No Content.

**Errors:** `404 not_found`, `403 forbidden`.

---

### Teacher · Questions

`Question` binds by `id` (`{question}`); an id belonging to a different exam resolves to 404. Author responses use `QuestionResource`, which **includes** the `correct` answer key.

#### `GET /v1/teacher/exams/{exam:uuid}/questions`

**Purpose:** List an exam's questions (ordered by `sort_order`, then `id`) including the answer key.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid).

**Response 200** — `QuestionResource` collection:

```json
{
  "data": [
    {
      "id": 101,
      "type": "mcq",
      "body": "What is the SI unit of force?",
      "options": ["Joule", "Newton", "Watt", "Pascal"],
      "correct": [1],
      "points": 2,
      "book_ref": { "book": "Physics G12", "page": 45, "qno": 3 },
      "sort_order": 1
    },
    {
      "id": 102,
      "type": "true_false",
      "body": "Force equals mass times acceleration.",
      "options": null,
      "correct": [true],
      "points": 1,
      "book_ref": null,
      "sort_order": 2
    }
  ]
}
```

**Errors:** `404 not_found`, `403 forbidden`.

---

#### `POST /v1/teacher/exams/{exam:uuid}/questions`

**Purpose:** Add a question to the exam.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Content-Type: application/json`, `Authorization: Bearer <token>`.

**Request body** (`QuestionRequest`) — the shape is type-dependent:

```json
{
  "type": "mcq",
  "body": "What is the SI unit of force?",
  "points": 2,
  "sort_order": 1,
  "options": ["Joule", "Newton", "Watt", "Pascal"],
  "correct": [1],
  "book_ref": { "book": "Physics G12", "page": 45, "qno": 3 }
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `type` | enum | yes | `mcq` \| `true_false` \| `short` \| `essay` \| `file` |
| `body` | string | required for `short`/`essay` | otherwise nullable (bubble-sheet MCQ may omit it) |
| `points` | int | no | ≥ 1 |
| `sort_order` | int | no | ≥ 0 |
| `options` | array | required for `mcq` | ≥ 2 items, each string ≤ 500 |
| `options.*` | string | — | ≤ 500 |
| `correct` | array | required for `mcq` and `true_false` | the answer key (hidden from students) |
| `correct.*` | mixed | — | MCQ = option index/indices (e.g. `[1]` or `[0,2]`); `true_false` = `[true]`/`[false]` |
| `book_ref` | object | no | `{ book (string), page (int ≥1), qno (int ≥1) }` — printed-book reference |

Type-specific shapes:
- **MCQ**: `options` (≥ 2) + `correct` as an array of correct option indices (supports multiple).
- **true_false**: `correct` = `[true]` or `[false]`; no `options`.
- **short / essay**: `body` required; graded manually — no `correct` needed.
- **file**: submission is an uploaded file; graded manually.
- **bubble_sheet MCQ**: MCQ with `book_ref` and (optionally) no `body`.

**Response 201** — `QuestionResource` (includes `correct`).

**Errors:** `422 validation_error` (e.g. MCQ without `options`/`correct`, `short`/`essay` without `body`), `404 not_found`, `403 forbidden`.

---

#### `PUT /v1/teacher/exams/{exam:uuid}/questions/{question}`

**Purpose:** Update a question. Same `QuestionRequest` rules as create.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Content-Type: application/json`, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid), `question` (int id; must belong to `exam`).

**Request body** — full `QuestionRequest` (`type` required). Example:

```json
{
  "type": "true_false",
  "body": "Force equals mass times acceleration.",
  "points": 1,
  "sort_order": 2,
  "correct": [true]
}
```

**Response 200** — updated `QuestionResource`.

**Errors:** `422 validation_error`, `404 not_found` (unknown question, or question not on this exam), `403 forbidden`.

---

#### `DELETE /v1/teacher/exams/{exam:uuid}/questions/{question}`

**Purpose:** Delete a question from the exam (hard delete).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid), `question` (int id; must belong to `exam`).

**Response 204** — No Content.

**Errors:** `404 not_found` (question not on this exam), `403 forbidden`.

---

### Teacher · Bubble-sheet builder

On-site MCQ authoring for a quiz/homework exam (doc 13 Phase 7). A **bubble sheet** is just a set of `mcq` questions on the exam persisted through the **existing `questions` table** — no new tables. Each question maps `text → body`, `options[] → options`, `correct_index → correct[0]`, `marks → points`; `exams.total_marks` mirrors Σ marks. The whole sheet is read/replaced at once (bulk upsert). The answer key (`correct_index`) is returned **only** on this teacher surface — a student attempt never sees it (`PublicQuestionResource` omits `correct`).

Auto-grading is driven by the backing exam's `grading_mode`: when it is `auto`, a submitted attempt is scored as `score = Σ marks of questions whose chosen option is correct`, finalised straight to `graded` (never routed to a teacher), and pass/fail is evaluated via `Exam::passed()` — `pass_mode=marks` compares the absolute score to `pass_value`, `pass_mode=percent` compares the ratio, and a null `pass_value` falls back to the legacy `pass_percent`.

#### `GET /v1/teacher/exams/{exam:uuid}/bubble-sheet`

**Purpose:** Read the whole sheet, answer key included (teacher-only).
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`, `academic-year`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`, **`X-Academic-Year: <uuid>`** (required).

**Response 200:**
```json
{
  "data": {
    "exam_uuid": "…",
    "grading_mode": "auto",
    "pass_mode": "marks",
    "pass_value": "25.00",
    "total_marks": 50,
    "questions": [
      { "id": 12, "text": "Q1", "options": ["A","B","C","D"], "correct_index": 2, "marks": 10, "sort_order": 0 },
      { "id": 13, "text": "Q2", "options": ["True","False"], "correct_index": 0, "marks": 40, "sort_order": 1 }
    ]
  }
}
```

#### `PUT /v1/teacher/exams/{exam:uuid}/bubble-sheet`

**Purpose:** Bulk-upsert (replace) the whole sheet. Existing questions are dropped and rebuilt in payload order.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`, `academic-year`

**Request body:**
```json
{
  "total_marks": 50,
  "questions": [
    { "text": "Q1", "options": ["A","B","C","D"], "correct_index": 2, "marks": 10 },
    { "text": "Q2", "options": ["True","False"], "correct_index": 0, "marks": 40 }
  ]
}
```

| field | type | required | notes |
|---|---|---|---|
| `total_marks` | int | no | must equal Σ `marks`; **derived from the sum when omitted** |
| `questions` | array | yes | at least one |
| `questions.*.text` | string | no | the question prompt (nullable) |
| `questions.*.options` | string[] | yes | at least two |
| `questions.*.correct_index` | int | yes | in `[0, len(options)-1]` |
| `questions.*.marks` | int | yes | ≥ 1 (integer marks — reuses the int `points`/`score` columns) |

**Response 200** — the same sheet resource as GET (persisted question ids + answer key).

**Errors:** `422 validation_error` (`error.details`: `total_marks` ≠ Σ marks, `questions` empty, `questions.*.options` < 2, `questions.*.correct_index` out of range, or missing `X-Academic-Year` → `academic_year`), `404 not_found` (cross-tenant exam), `403 forbidden`.

---

### Teacher · Grading

#### `GET /v1/teacher/exams/{exam:uuid}/submissions`

**Purpose:** List submitted/graded attempts for an exam (newest submission first), optionally filtered to those still needing manual grading. Includes basic student identity.
**Auth:** 🧑‍🏫 role:teacher
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`.

**Path params:** `exam` (uuid).

**Query params**

| Param | Type | Notes |
|---|---|---|
| `filter[needs_grading]` | bool | When truthy, only attempts with `needs_manual_grade = true` |

**Response 200** — plain array (not a Resource, no pagination). Only `submitted` and `graded` attempts appear:

```json
{
  "data": [
    {
      "attempt_id": 45,
      "student": { "uuid": "b1c2...", "name": "Mohamed Ali Hassan", "phone": "+201112223334" },
      "status": "submitted",
      "score": 3,
      "max_score": 8,
      "needs_manual_grade": true,
      "submitted_at": "2026-09-01T10:15:00+00:00"
    }
  ]
}
```

**Errors:** `404 not_found`, `403 forbidden`.

---

#### `POST /v1/teacher/exams/{exam:uuid}/attempts/{attempt}/grade`

**Purpose:** Assign points to the manually-graded (subjective) answers, recompute the total, and finalise the attempt. When nothing is left pending the attempt becomes `graded` (and points are awarded if passing); otherwise it stays `submitted`. For **`upload`-kind homework** the teacher/assistant may also attach optional written `feedback` and a corrected/annotated `corrected_file`, both surfaced to the student alongside their grade.
**Auth:** 🧑‍🏫 role:teacher (or assistant with `homework`)
**Middleware:** `tenant`, `auth:sanctum`, `active`, `role:teacher,assistant`, `permission:homework`

**Request headers:** Host, Accept, `Authorization: Bearer <token>`. `Content-Type: application/json` for grades only; **`multipart/form-data`** when attaching a `corrected_file`.

**Path params:** `exam` (uuid), `attempt` (int id; must belong to `exam`).

**Request body** (`GradeAttemptRequest`) — `grades` maps **question id → awarded points**, plus optional feedback/corrected file:

```json
{
  "grades": {
    "103": 4,
    "104": 0
  },
  "feedback": "Good work — see the corrected copy."
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `grades` | object | yes | ≥ 1 entry; `question_id => points` |
| `grades.*` | int | — | ≥ 0; clamped to the question's max `points`; `is_correct` set to `awarded === max` |
| `feedback` | string | no | ≤ 5000 chars; written comment on the submission |
| `corrected_file` | file | no | ≤ `assessment.upload_max_kb` (20 MB); `assessment.upload_mimes`; stored on the private `assignments/` disk |

Only keys present in the attempt's stored `answers` are applied; unknown question ids are ignored. `feedback` / `corrected_file` are only overwritten when supplied — a re-grade without them keeps what was attached before.

**Response 200** — finalisation summary (with feedback + corrected-file presence):

```json
{
  "data": {
    "attempt_id": 45,
    "status": "graded",
    "score": 7,
    "max_score": 8,
    "needs_manual_grade": false,
    "feedback": "Good work — see the corrected copy.",
    "corrected_file": { "name": "corrected.pdf", "size": 12345 }
  }
}
```

`corrected_file` is `null` when none is attached. The stored file path is never exposed; the student downloads it via `GET /v1/exams/{exam}/attempts/{attempt}/corrected-file`.

**Errors:** `404 not_found` (attempt not on this exam), `422 validation_error` (empty `grades`, negative points, oversize/rejected file), `403 forbidden`.
