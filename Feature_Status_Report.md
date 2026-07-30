# Elameed Education CMS — Feature Implementation Status

*Prepared for management review — July 30, 2026*

## Executive summary

The platform (a Laravel 13 modular monolith, `elameed-education-cms`) is well advanced. As of the latest full endpoint sweep it carries 198 documented API endpoints across 13 modules with 224 automated tests (935 assertions) passing. Against the teacher/student feature spec we defined, the large majority is already built and working — including some of the more intricate requirements (per-part lesson dependencies, homework graded-vs-submitted unlock rules, bubble-sheet vs file homework). Four concrete gaps remain, all scoped below and handed off as a ready-to-run engineering task.

## Teacher features — done

**Course/Unit/Lesson management.** Full CRUD on courses, units, lessons, and categories (`Teacher\CourseController`, `UnitController`, `LessonController`, `CategoryController`). A lesson is not a single video — it's an ordered list of typed sections (video, PDF, assignment), managed via `LessonSection` / `Teacher\LessonSectionController`.

**Dependencies within and across lesson parts.** A section can depend on another section (in the same or a different lesson), with the dependency marked mandatory (hard-blocks access) or optional (advisory only) — `ContentDependency` + `ContentUnlockService`, authored at `Teacher\ContentDependencyController`. This is the "part of a lesson depends on another part" requirement.

**Required vs. optional parts.** Each section carries an `is_required` flag, and each dependency carries mandatory/optional enforcement — teachers can mark any part as required or optional.

**Homework type per lesson.** A teacher already designates each homework as either a file upload (student submits a file, staff mark it "corrected") or an online exam, which can itself be a standard quiz or a bubble-sheet exam (`AssignmentKind`: upload/onsite; `Exam.mode`: standard/bubble_sheet).

**Homework grading.** Teacher/assistant grade submitted attempts (`Teacher\ExamGradingController`); a homework-gated next item unlocks specifically once the attempt reaches `graded` status, not merely on submission — matching the "student can only proceed once homework is marked corrected" rule.

**Student roster management.** Full CRUD over students — create, edit, list, view, remove, and block/unblock (block = setting membership status to suspended) — via `Teacher\StudentController`.

**Lesson availability & extension requests.** Teacher sets each lesson's viewing window (default 7 days) and how many extension requests a student may make plus how many hours each grants (`max_extensions`, `extension_hours` on `Lesson`); students request, teacher/assistant approves or denies (`LessonExtensionRequest`, `LessonAvailabilityService`).

**Packages.** Teacher can bundle courses, units, or individual lessons into one sellable package (`Bundle`/`BundleItem`, `Teacher\BundleController`).

## Teacher features — partially done

**Unit-to-unit dependencies.** Currently hardcoded to one rule: a unit's first lesson is blocked until the *immediately preceding* unit's exam is answered. There is no way yet for a teacher to configure an arbitrary dependency (e.g., "Unit 5 requires Unit 2," or "Unit 5 requires one specific lesson inside Unit 2") the way section-level dependencies already work.

## Teacher features — not yet built

1. **Manual access override.** No mechanism exists for a teacher/assistant to grant one specific student direct access to a locked lesson, section, or unit while leaving the dependency rule in place for everyone else.
2. **Configurable unit-level dependencies** (expanding on the partial item above).
3. **Richer homework grading** — grading today is a numeric per-question score only. There's no field yet for written feedback or for the teacher to attach a corrected/annotated version of the student's file.
4. **Bulk student-history import via Excel** — no import feature exists. Teachers cannot yet upload a spreadsheet to bulk-update student records.

## Student features — done

- Purchase a single lesson or a package of lessons (checkout → `Bundle`).
- Each lesson has a countdown access window (default 7 days) that starts when the student opens it, not at time of purchase — so in a package, each lesson's clock only starts once the student reaches it (naturally sequential, since reaching it also requires the prior dependency to clear).
- Request a time extension (default 24 hours), capped by the teacher's per-lesson limit.
- Locked content (lesson, exam, or homework-gated item) unlocks automatically once its prerequisite is submitted, passed, completed, or graded, per the trigger the teacher configured.
- Submit homework either as a file upload or as an online (bubble-sheet) exam, depending on how the teacher set up that lesson.
- View a grade once homework is corrected.

## Student features — remaining

- View written feedback and a corrected/annotated file for graded homework — blocked on the same grading gap listed above (the data isn't captured yet, so there's nothing to show).

## Recommended next step

The four gaps above are written up as a single, codebase-grounded engineering brief (`CLAUDE_CODE_TASK_PROMPT.md`, already delivered) that references the exact existing files/patterns to extend, so engineering can pick it up directly. Suggested priority: manual override first (unblocks day-to-day teacher support requests), then richer grading (visible student-facing improvement), then configurable unit dependencies, then the Excel import (lowest urgency, admin convenience only).

## Appendix — platform health snapshot

- 198 documented API endpoints across 13 modules (Tenancy, Identity, Catalog, Media, Commerce, Wallet, Centers, Assessment, Engagement, Notifications, Reporting, Platform Admin, Billing).
- 224 automated tests / 935 assertions passing as of the latest full endpoint sweep (2026-07-26).
- No open severity-1 defects; two previously-flagged issues (parent-unlink 500, playback 500 on missing media) have been fixed and regression-tested.
