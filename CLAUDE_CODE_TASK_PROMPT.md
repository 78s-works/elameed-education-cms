Implement the remaining gaps in the Elameed Education CMS (Laravel 13 modular monolith at `elameed-education-cms/`) so the platform fully matches the feature spec below. Most of this spec is already built — read the existing code first, then close only the real gaps. Do not rebuild anything that already works.

## How this codebase is organized (read before touching anything)

- Modular monolith: each bounded context lives in `app/Modules/<Name>/{Http,Models,Enums,Services,...}`. No fat shared controllers.
- Start with `docs/01_Modules.md` (module map) and `docs/api/catalog.md` + `docs/api/assessment.md` (endpoint-level detail for the two modules most of this work touches).
- Tenant scoping: `BelongsToTenant` trait for normal queries; access-critical services use `withoutGlobalScopes()` + explicit `tenant_id` (see `ContentUnlockService`, `LessonProgressionService`, `EnrollmentService`) — follow this pattern for anything gating access.
- Audit every state-changing admin/teacher action via `App\Support\Audit\AuditLogger` (see `student.status_changed`, `assistant.created`, `comment.moderated` for examples).
- Domain errors use `App\Support\Exceptions\DomainException` (see `PlanLimitGuard` for the `403 plan_limit_reached` pattern) — reuse this for new business-rule rejections instead of ad hoc exceptions.
- Tests live in `tests/Feature/<Module>/...`; add coverage there, matching existing test style (see `tests/Feature/Catalog/LessonContentModelTest.php`, `tests/Feature/Catalog/LessonProgressionTest.php`).
- Run `php artisan test` before declaring anything done.

## Already implemented — do not redo

- Course → Unit → Lesson → typed **Sections** hierarchy (`Course`, `Unit`, `Lesson`, `LessonSection`), with section types including video, PDF, and `assignment`.
- Assignment sections carry `AssignmentKind`: `upload` (student submits a file, teacher/assistant grades it "corrected") or `onsite` (answered in-browser as an exam/quiz attempt). `Exam.mode` separately supports `standard` vs `bubble_sheet` — an onsite assignment can be a bubble-sheet exam. This already covers "teacher designates whether a lesson's homework is a file upload or an online bubble-sheet exam."
- Section-level dependencies (`ContentDependency`: `section_id` depends on `depends_on_section_id`, `trigger` = `submitted|passed|completed|graded`, `enforcement` = `mandatory|optional`), evaluated by `ContentUnlockService`. This is the "part of a lesson depends on another part" requirement.
- Cross-lesson/cross-unit sequencing (`LessonProgressionService`): a lesson is locked until the previous lesson's required upload-homework is submitted **and** graded (R5.2), and the first lesson of a unit is locked until the previous unit's published exam is answered (R5.3).
- Per-student lesson access windows (`LessonAccessWindow`: `started_at`/`expires_at`/`locked_at`), opened lazily on `POST /lessons/{id}/start` (not at purchase time) — this already gives sequential/lazy timers for package purchases, since a lesson's clock only starts once the student actually opens it, which in turn is gated by the dependency rules above.
- Extension requests (`LessonExtensionRequest` + `LessonAvailabilityService`): student requests +`extension_hours`, capped per-lesson by teacher-set `max_extensions`; teacher/assistant grants or denies via `/teacher/extension-requests`. This is the "teacher controls how many extension requests a student can send" requirement.
- Teacher CRUD over students (`Teacher\StudentController`): create, edit, list, show, remove, and "block" via `PATCH` with `status: suspended` (`MembershipStatus::Suspended`) — functionally the block/unblock feature, just confirm the API/UI label this clearly as "block/unblock" rather than only "status".
- Packages/bundles (`Bundle`, `BundleItem`) grouping courses/units/lessons into one purchase, each item's access window governed by the same lazy-start lesson window logic above.
- Homework file upload during an attempt (`UploadAttemptFileRequest`, private `assignments/` disk).

## Real gaps — implement these

1. **Manual teacher access override.** There is currently no way for a teacher/assistant to grant a specific student direct access to a specific locked lesson, section, or unit, bypassing unmet dependencies (`ContentUnlockService` / `LessonProgressionService` have no override lookup). Add:
   - A new tenant-scoped table, e.g. `content_access_overrides` (`tenant_id`, `user_id`, overridable target — polymorphic or one of `lesson_id`/`section_id`/`unit_id`, `granted_by`, `granted_at`, optional `note`), RLS-enabled like the other Catalog tables.
   - A teacher/assistant endpoint to grant/revoke an override for a student on a given target.
   - Both `ContentUnlockService::isSectionLocked`/`lockMap` and `LessonProgressionService::progressionLock` must check for an active override for that user+target and short-circuit to unlocked if found.
   - Audit-log grant/revoke.

2. **Configurable, non-sequential unit-level dependencies.** Today unit gating is hardcoded to "previous unit's exam" (R5.3) — there's no way for a teacher to say "Unit 5 depends on Unit 2" (not just the immediately preceding unit) or "Unit 5 depends on a specific lesson/exam inside Unit 2." Extend the dependency model (either generalize `content_dependencies` to also reference units, or add a parallel `unit_dependencies` table: `unit_id`, `depends_on_unit_id` or `depends_on_section_id`, `trigger`, `enforcement`) and wire it into `LessonProgressionService` so R5.3 becomes "configured unit prerequisites" rather than only "the immediately previous unit." Keep the existing previous-unit-exam behavior as the default when no explicit dependency is configured, so current data/tests don't break.

3. **Richer homework grading.** `GradeAttemptRequest`/`GradingService` currently only accept per-question integer point grades. Add, for `upload`-kind homework attempts specifically:
   - Optional written feedback/comment text.
   - An optional corrected/annotated file upload that the teacher/assistant attaches to the graded attempt, visible to the student alongside their grade.
   Store this on `ExamAttempt` (new nullable `feedback` text column + a corrected-file reference, e.g. via a `MediaAsset`/private-disk pointer) and surface it in the student-facing attempt resource once graded.

4. **Bulk student-history import via Excel.** No import feature exists at all. Add a teacher-only endpoint (e.g. `POST /teacher/students/import`) that accepts an `.xlsx`/`.csv` upload and updates each matched student's history/profile fields in bulk (match by phone/email against existing `StudentProfile`/`User` rows within the tenant; report per-row success/failure, matching the per-item `applied/duplicate/failed` pattern already used in `Centers`' offline sync). Use `maatwebsite/laravel-excel` (or `openspout/openspout` if you'd rather avoid the extra dependency) — check what's already in `composer.json` before adding a new package. Clarify with row-level validation what "student history" fields are expected to map to (reuse `StudentProfile::fields()` as the canonical list) rather than inventing new columns.

## Acceptance criteria

- New/changed migrations, models, enums, services, controllers, form requests, and resources follow the existing per-module structure and RLS/audit/DomainException conventions above.
- Feature tests added under `tests/Feature/...` for each of the four gaps (override bypass, unit-dependency gating, homework feedback/corrected-file, Excel import happy-path + row-level failure).
- `php artisan test` passes.
- `docs/01_Modules.md` and the relevant `docs/api/*.md` files updated to document the new endpoints/behavior, consistent with the existing documentation style.
