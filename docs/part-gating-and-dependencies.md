# Lesson-part gating & content dependencies

Status: ✅ built (sequential gate) · ⏳ dormant (explicit dependencies) — 2026-08-12
Module: `Catalog` (M04) · backing exams in `Assessment` (M05)

This is the full reference for how a **lesson part** (`lesson_sections` row) can be
made to depend on another part before a student may open it. There are **two
separate systems** in the codebase; only the first is wired into the runtime.

| System | What it expresses | Wired? |
|---|---|---|
| **Sequential per-part gate** (`gate_rule`) | An implicit "clear part N before part N+1" gate, ordered by `sort_order` | ✅ enforced |
| **Explicit content dependencies** (`content_dependencies`) | "Part A depends on part B" — any pair, any trigger | ⏳ schema + model only, **not enforced** |

Plus two adjacent gates that share the same runtime endpoint:

| Gate | Scope | File |
|---|---|---|
| Legacy solution gate | Answer videos inside one lesson | `ContentUnlockService` |
| Lesson progression gate | Open a lesson from the previous lesson's state | `LessonProgressionService` |
| Study-mode visibility | Hide parts outside a student's channel | `StudentPartVisibility` |

---

## 1. Data model

### 1.1 `lesson_sections` (the part)

The part table is reused across two generations (VD change set §7, doc 12 §7).
Gating columns added by `2026_08_04_000005_restructure_lesson_sections.php`:

| Column | Type | Meaning |
|---|---|---|
| `type` | enum | `video` \| `homework` \| `quiz` (new) · `lecture_video` \| `pdf` \| `quiz_solution` \| `hw_solution` (legacy) |
| `access_mode` | enum, nullable | `center` \| `online` \| `both` — the part's channel, ⊆ its lesson's `access_mode` |
| `delivery` | enum, nullable | `video_upload` \| `image_upload` \| `pdf_upload` \| `bubble_sheet` |
| `gate_rule` | enum, nullable | `must_pass` \| `must_submit` — **how this part gates the parts after it** |
| `max_tries` | uint, nullable | per-student retake cap on the backing exam (null = unlimited) |
| `exam_id` | FK, nullable | the backing `Exam` for a quiz/homework part (holds degree + grading) |
| `sort_order` | int | ordering — the sequential gate walks parts in this order |

All gating columns are nullable; legacy sections leave them `NULL` and are
never sequential-gated. A part only gates when it has **both** a `gate_rule` and
an `exam_id` (`ContentUnlockService::isGatingPart`).

### 1.2 `part_pass_overrides` (teacher manual pass — LP-D3)

`PartPassOverride` — one row per `(lesson_section_id, user_id)`, DB-unique.
Presence makes the runtime treat the student as having passed that part
regardless of score or exhausted retakes. Fields: `lesson_section_id`, `user_id`,
`granted_by`, `granted_at`, `note`.

### 1.3 `content_dependencies` (explicit deps — DORMANT)

`2026_07_28_000002_create_content_dependencies_table.php`. One unlock rule:
section `section_id` stays locked until `trigger` is met on
`depends_on_section_id`.

| Column | Meaning |
|---|---|
| `section_id` | the dependent part (FK → lesson_sections, cascade) |
| `depends_on_section_id` | the prerequisite part (FK → lesson_sections, cascade) |
| `trigger` | `submitted` \| `passed` \| `completed` \| `graded` |
| `enforcement` | `mandatory` (blocks) \| `optional` (advisory only) |

Unique pair `(section_id, depends_on_section_id)`. A part may depend on many
others. **This table has no writer and no reader in the runtime** — see §7.

---

## 2. Enums

- `GateRule` — `must_pass` \| `must_submit` (`app/Modules/Catalog/Enums/GateRule.php`).
- `DependencyTrigger` — `submitted` \| `passed` \| `completed` \| `graded`.
- `DependencyEnforcement` — `mandatory` \| `optional`.
- `LessonSectionType` — `authoringValues()` = `[video, homework, quiz]`; `backsExam()` true for quiz/homework.

---

## 3. Sequential per-part gate ✅ (the live one)

Source: `app/Modules/Catalog/Services/ContentUnlockService.php`.

### 3.1 Rule

Parts are ordered by `sort_order`, then `id`. A quiz/homework part that carries
a `gate_rule` + backing exam **gates every LATER part** until the student clears
it. Once one gate is unmet, all following parts lock (a running `$gateBlocked`
flag, `ContentUnlockService.php:69-85`).

- `must_submit` — the backing exam has a submitted attempt (any score).
- `must_pass` — the best submitted attempt meets the exam's degree of success
  (`Exam::passed`, best across tries — LP-14), **OR** a `part_pass_overrides`
  row exists for the student (LP-D3).

Exams themselves are **never** locked — the gate sequences *content*, not exam
access; attempt endpoints stay reachable so a student can actually clear the
gate. A part with no `gate_rule`/`exam_id`, or a missing backing exam, gates
nothing.

### 3.2 Entry points

| Method | Use |
|---|---|
| `lockMap($tenantId, $userId, $lesson)` | `section_id => isLocked` for every part of a lesson |
| `isSectionLocked(...)` | single-part convenience (delegates to `lockMap`) |
| `isAssetLockedInLesson(...)` | guards the media-playback endpoint so the gate can't be bypassed by requesting a token directly |
| `partResult(...)` | per-part `{ passed, submitted, attempts_used, max_tries, best_score, best_max, degree_of_success, via_override }` (VD F14) |

### 3.3 `max_tries` enforcement

Retake cap lives in `Assessment\Http\Controllers\AttemptController::attemptCap`
(`AttemptController.php:284-296`). When the exam backs a part, the part's
`max_tries` governs (null → 0 → unlimited); otherwise the exam's own
`attempts_allowed`. Exceeding it → `409 No attempts remaining for this exam.`

### 3.4 Overrides vs the gate — important distinction

A staff **content-access override** (`ContentAccessOverride`) opens a part's
*display* outright, but does **not** satisfy a `must_pass` gate for later parts —
only a real pass or a **part_pass_override** does. `lockMap` evaluates the gate
from real progress so a content override never masks a missing pass
(`ContentUnlockService.php:82-85`).

---

## 4. Legacy solution gate ✅

Also in `ContentUnlockService` (`lockedByType`, doc 11). Answer videos are
hidden until the matching lesson-level exam is submitted:

- `quiz_solution` — locked until this lesson's `lesson_quiz` has a submitted attempt.
- `hw_solution` — locked until this lesson's `homework` has a submitted attempt.

Retired in Phase 5 alongside the legacy `LessonSectionType` cases.

---

## 5. Lesson progression gate ✅ (cross-lesson, not part-level)

`app/Modules/Catalog/Services/LessonProgressionService.php`. Decides whether a
student may **open a lesson** at all, before part gating runs:

1. Free-preview lesson → open.
2. Active staff override on the lesson/unit → open.
3. Sequential package unlock (B14 / R5) — a lesson bought in a package stays
   locked until the previous lesson in the package sequence is completed.
4. First lesson of a unit → open.
5. Otherwise the previous lesson's `lesson_quiz` AND `homework` must each be
   **submitted** (grading not required). Lock reasons: `prev_quiz_missing`,
   `prev_homework_missing`.

Surfaced as `423 Locked` with the machine reason in the sections endpoint.

---

## 6. Study-mode visibility ✅ (B12 / LP-6)

`app/Modules/Catalog/Services/StudentPartVisibility.php`. After access + gating,
parts outside the student's `study_mode` channel are dropped:
`center` student sees `center`+`both`, `online` sees `online`+`both`, `both`
sees all. Legacy parts with null `access_mode` always show. Applied in the
controller before locking.

---

## 7. Explicit content dependencies ⏳ (DORMANT — the answer to "can a part depend on another part?")

**Yes, this is exactly what `content_dependencies` was designed for** — an
explicit "part A depends on part B" edge with a configurable trigger, unlike the
position-based sequential gate. **But it is not wired.**

What exists:

- Migration `2026_07_28_000002_create_content_dependencies_table.php` (§1.3).
- Model `App\Modules\Catalog\Models\ContentDependency` — `section()`,
  `dependsOnSection()`, `isMandatory()`.
- Enums `DependencyTrigger`, `DependencyEnforcement`.
- Relation `LessonSection::dependencies()` (`LessonSection.php:85-88`).

What is missing (grep of `app/` confirms):

- `ContentUnlockService::lockMap` never reads `content_dependencies` — the only
  gates it applies are the sequential `gate_rule` gate and the legacy solution
  gate.
- No controller/request writes dependency rows — no teacher endpoint to create
  or edit them.
- No resource surfaces them to the student client.

So the `trigger=completed` / `trigger=graded` cases, the `optional` (advisory)
enforcement, and arbitrary (non-sequential) prerequisites are **specified but
inert**. The only dependency behaviour a student experiences today is the
sequential `gate_rule` gate.

### To activate it

Wire `content_dependencies` into `ContentUnlockService::lockMap`: for each part,
load its mandatory `dependencies()`, evaluate each rule's `trigger` against the
prerequisite part's exam attempts / lesson progress, and mark the part locked if
any mandatory prerequisite is unmet. Add a teacher CRUD surface + request
validation (reject self-deps and cycles), surface `optional` deps on
`LessonSectionResource` as advisory hints, and add coverage mirroring
`PartGatingTest`. Per project Rule B, sync `docs (1)/04_API_Specification.md`
(new endpoints) and this file's status header when it ships.

---

## 8. API surface

### Student

```
GET /api/v1/lessons/{lesson}/sections
```
`StudentLessonSectionsController::index`. Requires enrolment (or free preview) →
else `403`. Runs the progression gate → `423 <reason>` if locked. Returns the
lesson's parts, filtered by study-mode, each stamped with `locked` and `result`.

### Teacher (`permission:content`)

```
GET    /api/v1/teacher/lessons/{lesson}/sections
POST   /api/v1/teacher/lessons/{lesson}/sections
PUT    /api/v1/teacher/lessons/{lesson}/sections/reorder
PUT    /api/v1/teacher/lessons/{lesson}/sections/{section}
DELETE /api/v1/teacher/lessons/{lesson}/sections/{section}
```

Pass-override (must_pass parts — LP-D3):
```
POST   /api/v1/teacher/lessons/{lesson}/sections/{section}/pass-override        { user_id, note? }
DELETE /api/v1/teacher/lessons/{lesson}/sections/{section}/pass-override/{user:uuid}
```
Duplicate override → `409`; revoke is idempotent (`204`).

`LessonSectionController::store/update` mints/updates the backing `Exam` for a
quiz/homework part inside a transaction; a video part carries no exam. Validation
in `LessonSectionRequest`: `gate_rule` required for homework/quiz; part
`access_mode` ⊆ lesson `access_mode`; `grading_mode=auto` ⇒ `delivery=bubble_sheet`;
`pass_mode=marks` ⇒ `total_marks` present and `pass_value ≤ total_marks`.

---

## 9. Response shape (`LessonSectionResource`)

```jsonc
{
  "id": 12,
  "lesson_id": 3,
  "type": "video",
  "name": "Part title",
  "access_mode": "both",
  "delivery": null,
  "gate_rule": null,           // on a gating quiz/homework part: "must_pass" | "must_submit"
  "max_tries": null,
  "sort_order": 1,
  "media_asset_id": 999,       // null when locked (media withheld)
  "youtube_url": null,         // null when locked
  "pdf_kind": null,
  "is_required": true,
  "exam": { "id": "<uuid>", "type": "...", "pass_mode": "...", "pass_value": 60, ... },  // when loaded
  "locked": false,             // student listing only
  "result": {                  // student listing, exam-backed parts only (VD F14)
    "passed": true, "submitted": true, "attempts_used": 2, "max_tries": 3,
    "best_score": 8, "best_max": 10, "degree_of_success": 80, "via_override": false
  }
}
```

A locked video part has its `media_asset_id` / `youtube_url` / `media` nulled so
the client can't reach the protected asset by ignoring the `locked` flag
(`LessonSectionResource::isContentGated`). PDF parts carry no gated asset and are
never locked.

---

## 10. Tests

`tests/Feature/Catalog/PartGatingTest.php` covers the live sequential gate:

- `must_submit` locks the later part until any submitted attempt.
- `must_pass` stays locked through a failing attempt; best-of-tries clears it.
- Teacher pass-override satisfies a `must_pass` gate without a passing score.
- `max_tries` caps retakes (`409`); null `max_tries` overrides the exam's own cap.
- Per-part `result` exposes degree of success + retake count (pass and fail cases).

Legacy solution gating is in `LessonProgressionTest`. There is **no** test for
`content_dependencies` (nothing to test — it's inert).
