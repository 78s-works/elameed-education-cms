# Handoff — `courses` retirement, P2 test migration

**Date:** 2026-08-17 · **Status:** app code + migrations + seeder DONE & green; **tests NOT migrated** (this is the remaining job) · **Nothing committed** (greenfield, DB reseeded).

## What the whole effort is

Retiring the legacy `Course` entity. Target model: `academic_years ➔ recursive packages ➔ standalone lessons ➔ parts`. No course node anywhere.

- **P1 (done):** the four course-*scoping* features (coupons, reviews, favorites, activation_codes) re-pointed to a polymorphic **lesson|package** target via `App\Support\Traits\HasContentTarget` (`target_type` + `target_id`, PackageItem-style tokens — not Eloquent morphTo).
- **P2 (done in app, THIS handoff = its tests):** the `Course` entity itself is gone.
- **P3 (not started):** frontend (`EDU-frontend`) rename of the course-named SPA surface.

## What is DONE (backend app + data) — do NOT redo

- Deleted: `Course` + `CourseCategory` models; `CourseResource`, `CourseDetailResource`, `CourseListController`, `CategoryController`, `CategoryResource`, `CategoryRequest`; the `create_courses_table` + `create_course_categories_table` migrations.
- Dropped columns (edited the squashed create-migrations in place — greenfield): `enrollments.course_id` + dormant `unit_id`/`bundle_id`; `lessons.course_id` + `unit_id`; `exams.course_id` + `unit_id`; `attendance_records.course_id`; `content_access_overrides.unit_id`; `questions.category_id`.
- `EnrollmentService`: removed `grantCourse` + `hasAccess(Course)`; `hasLessonAccess`/`hasExamAccess` de-coursed; access is per-lesson (`lesson_id`) or per-exam (`exam_id`); package buy fans out to per-lesson rows. Added `hasPackageAccess`.
- `CheckoutService`: removed `priceCourse` + `OrderItem::TYPE_COURSE`; cart types now `lesson`/`package`/`wallet_topup` (`CartRequest`). `FulfillOrderService` + `CouponService::contentSubtotal` de-coursed.
- `ExamType::UnitExam` removed (+ `linksUnit()`, the one-per-unit rule, `unit_exam` validation). Exams link only `lesson_id` (or free).
- `PublicCatalogController`: `show()`/`courses()` gone; default view = **packages**, `?view=lessons` = lessons.
- Routes: `GET /courses` → **`GET /catalogue`**; removed `GET /courses/{slug}`, `GET /teacher/courses`, `/teacher/categories`. `GET /me/courses` now returns owned lessons.
- Teacher manual enroll `POST /teacher/students/{uuid}/enrollments`: body `{target_type: lesson|package|exam, target}` (no `course`). See `StudentEnrollmentController` for exact response shape.
- Peripherals de-coursed: Centers attendance (controllers/resource/request/service/model), Engagement (Progress/Forum/CommentResource), Identity (StudentActivity/StudentController), Reporting `StudentCoursesController` (now lists lessons), PlatformAdmin (AdminReport/TenantInsights), Tenancy LandingResolver (dead course-poster/rating/stat helpers removed; `applyEnrollment` uses lesson_id) + `UpdateTeacherLandingRequest`.
- **Verified:** `php artisan migrate:fresh --seed --seeder='Database\Seeders\AhmedTammamAcademySeeder'` exits **0**. Seeder fully migrated to lessons/packages.
- Docs synced: `docs (1)/03_Data_Model.md` §6 (P1+P2 deltas), `docs (1)/04_API_Specification.md` (catalogue/exam/enroll/reviews/favorites/codes rows).

## What REMAINS — migrate these 28 test files

They still reference the deleted `Course`/`CourseCategory`, `course_id`, `grantCourse`, `UnitExam`, `'type' => 'course'` carts, or `GET /courses*`. They will fatal/fail until fixed.

```
tests/Feature/Assessment/BubbleSheetTest.php
tests/Feature/Assessment/ExamsTest.php
tests/Feature/Catalog/ContentAccessOverrideTest.php
tests/Feature/Catalog/CourseCatalogTest.php            <- likely DELETE (public course catalogue is gone)
tests/Feature/Catalog/LessonContentModelTest.php
tests/Feature/Catalog/LessonProgressionTest.php        <- delete the unit_exam methods
tests/Feature/Catalog/PartGatingTest.php
tests/Feature/Catalog/PartVisibilityByStudyModeTest.php
tests/Feature/Catalog/StudentLibraryTest.php
tests/Feature/Centers/SectionAttendanceTest.php
tests/Feature/Commerce/CheckoutTest.php
tests/Feature/Commerce/CouponTest.php                  <- P1-migrated but still has Course cart/helper; finish it
tests/Feature/Commerce/InvoicePdfTest.php
tests/Feature/EndpointSmokeTest.php                    <- big route smoke; known-stale; update course routes
tests/Feature/Engagement/CommentsAndForumTest.php
tests/Feature/Engagement/GamificationTest.php
tests/Feature/Identity/TeacherStudentManagementTest.php
tests/Feature/Identity/TeacherStudentsTest.php
tests/Feature/Media/EncryptedHlsTest.php
tests/Feature/Media/MediaNotReadyTest.php
tests/Feature/Media/MediaUploadTest.php
tests/Feature/Media/PlaybackAuthorizationTest.php
tests/Feature/Media/RemoteMediaHostTest.php
tests/Feature/Media/YoutubeLessonTest.php
tests/Feature/PlatformAdmin/AdminTenantDetailTest.php
tests/Feature/Security/HardeningTest.php
tests/Feature/Tenancy/EndpointImprovementsTest.php
tests/Feature/Tenancy/LandingV2Test.php                <- P1-migrated but still creates Course; finish it
```

### Canonical de-course rules (apply per file)

1. `new Lesson([... 'course_id'=>X, 'unit_id'=>Y ...])` → drop those keys. Lessons are standalone (`academic_year_id` auto-fills; set `$lesson->academic_year_id = $year->id` if a specific year matters).
2. Any `Course`/`CourseCategory` created only to anchor a lesson → remove; make the Lesson standalone. Delete now-unused `use ...Course;` imports.
3. `grantCourse($t,$u,$course,..)` → `grantLesson($t,$u,$lesson,..)`. `hasAccess($t,$u,$course)` → `hasLessonAccess($t,$u,$lesson)`.
4. Cart `['type'=>'course','course'=>$c->uuid]` → `['type'=>'lesson','lesson'=>$lesson->id]` (lesson `is_purchasable=true` + `price_minor`). Packages stay `['type'=>'package','package'=>$pkg->uuid]`.
5. Exams: creation with `course_id`/`unit_id` → keep only `lesson_id` (lesson_quiz/homework) or none (free_exam). **Delete whole test methods whose subject is `unit_exam`.**
6. DB assertions `assertDatabaseHas('enrollments', ['course_id'=>..])` → `['lesson_id'=>..]`.
7. Routes: `GET /courses` → `GET /catalogue` (default lists packages; `?view=lessons`). `GET /courses/{slug}` is GONE — delete those assertions. `GET /me/courses` now returns lessons.
8. Teacher enroll body `{course}`/`{target_type:course}` → `{target_type:lesson, target:$lesson->id}`; adjust response-shape assertions to `lesson_id`/`package_id` (see `StudentEnrollmentController`).
9. `CourseCatalogTest.php`: its whole subject is the removed public course catalogue — **delete it** (or salvage only lesson/package `/catalogue` cases).
10. Activation codes: `type=course`/`CodeType::Course` → `type=content` + `target_type`/`target_id`; enroll assertion `course_id` → `lesson_id` (see the already-migrated `tests/Feature/Centers/CentersTest.php` for the pattern).

Good already-migrated references to copy the pattern from: `tests/Feature/Commerce/CouponTest.php` (target-scoped coupon), `tests/Feature/Engagement/FavoritesTest.php`, `tests/Feature/Engagement/TeacherReviewsTest.php`, `tests/Feature/Centers/CentersTest.php`.

### ⚠️ Run tests SERIALLY — do not parallelize

The test DB `elameed_test` **wedges** under concurrent `migrate:fresh` (RefreshDatabase). Fix and run **one file at a time**:

```bash
php artisan test tests/Feature/Catalog/LessonProgressionTest.php
```

Do NOT run multiple test agents/processes against `elameed_test` at once (that is what stalled the automated pass). If it wedges, recreate the DB (drop/create `elameed_test` via PDO) and continue one file at a time. A spare `elameed_test2` DB exists for a second lane if needed.

### Definition of done

- `grep -rnE "\bCourse\b|course_id|grantCourse|UnitExam|'type' => 'course'" tests` → only unrelated hits (e.g. "of course" prose).
- Full suite green: `php artisan test`.
- Then update `docs (1)/12_VD_Requirements_Change_Set.md` + `13_VD_Teacher_Panel_Execution_Phases.md` + `05`/`07` build-status banners to mark the courses retirement complete, and start **P3 (frontend)** — see `EDU-frontend` course-named components/routes and the memory note `project_courses_retirement`.

### App-code note (intentional, not a leftover)

`app/Modules/Tenancy/Support/LandingSchema.php` still accepts an ignored `course_ids`/`category_id` config key on the "courses" landing section — kept until the P3 frontend editor is migrated. The resolver ignores it.
