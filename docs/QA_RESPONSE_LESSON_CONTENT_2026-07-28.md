# QA Response — Lesson Content, Dependencies, Availability & Countdown (28 Jul 2026)

Triage of the QA report [🧪 QA Report — Lesson Content, Dependencies, Availability & Countdown](https://app.notion.com/p/3ab3a01963fe811a815ed598ea8f3f65).

## TL;DR

**Backend for all four features is implemented, route-registered, and covered by
passing tests.** The QA pass was a black-box run against a **stale / wrong build**
(the report flags this caveat itself in §1). The genuine open items are:

- **BUG-1 (500)** — an environment/deploy problem, not a code defect. Highest priority.
- **BUG-2/3/4/5/6** — the backend exists; the gap is **frontend not wired** to the new
  endpoints (student & teacher apps live in a separate repo).

No backend code change was required to close features 1–4. The API contract is
documented in [`api/catalog.md`](api/catalog.md) (§ *Lesson content sections*,
*Content dependencies*, *Lesson availability*, *Extension requests*,
*Student · Lesson content & access*).

## Backend evidence

| Feature | QA verdict | Backend reality (this repo) |
|---|---|---|
| 1. Flexible lesson content | 🟡 Partial | `lesson_sections` table + `LessonSection` model + `LessonSectionType` (5 types incl. `assignment_video`). PDFs carry a **semantic** `PdfKind` = `lecture_notes` / `assignment_answer_sheet` / `exam_answer_sheet` — the "missing semantic type" QA reported **exists**. |
| 2. Content dependencies | 🔴 Not impl. | `content_dependencies` table + `ContentDependency` + `ContentDependencyController` + `ContentUnlockService::lockMap()`. `mandatory` locks, `optional` advisory. Student sections stamped with computed `locked`. |
| 3. Availability & extensions | 🔴 Not impl. | `availability_days` / `max_extensions` / `extension_hours` on `lessons`; `lesson_access_windows` (per-student window, auto-lock at expiry); `lesson_extension_requests` + teacher grant/deny; `LessonAvailabilityService`. |
| 4. Countdown timer | 🔴 Not impl. | `GET /lessons/{lesson}/access` returns server-computed `remaining_sec` + `expires_at` + `locked`. Timer is a **client render** of that state (client must not trust its own clock). |

Route registration: `routes/api.php` lines 216, 223, 341–357.
Tests: `tests/Feature/Catalog/LessonContentModelTest.php`,
`tests/Feature/PlatformAdmin/AdminTenantDetailTest.php` (green).

## Bug-by-bug resolution

| ID | QA claim | Root cause | Resolution owner |
|---|---|---|---|
| BUG-1 | Superadmin tenant detail → HTTP 500 | Deterministic on staging; code path is null-safe and test-green. Prime suspect: **staging DB missing migrations** — `media_assets.size_bytes` (`2026_07_27_000001`), read by `TenantInsights::storageMb()` via `sum('size_bytes')`. Missing column ⇒ SQL error on every tenant detail. | **Backend/DevOps** — deploy + `php artisan migrate --force`. Confirm with staging `laravel.log`. |
| BUG-2 | Dependencies feature absent | Backend present; **teacher/student UI never calls** `/sections/{section}/dependencies`. | **Frontend** |
| BUG-3 | Availability/extension absent | Backend present; UI never calls `/availability`, `/start`, `/access`, `/extension-request`. | **Frontend** |
| BUG-4 | Countdown timer absent | Backend present (`/access` → `remaining_sec`); no client timer component. | **Frontend** |
| BUG-5 | Teacher resource not visible to student | QA used the **old Engagement `Attachment`** flow. New model = **lesson sections**. Teacher UI must post to `/teacher/lessons/{lesson}/sections`; student page must read `/lessons/{lesson}/sections`. | **Frontend** |
| BUG-6 | PDF lacks semantic type | Same — QA saw the old attachment `kind` dropdown (image/audio/file). New `pdf` section carries `pdf_kind` (3 semantic values). | **Frontend** |

## Frontend wiring checklist

All endpoints already live; payloads in [`api/catalog.md`](api/catalog.md).

**Teacher**
- [ ] Section builder → `GET/POST/PUT/DELETE /v1/teacher/lessons/{lesson}/sections` (send `type`, `title`, `media_asset_id`|`exam_id`, `pdf_kind` for pdf, `sort_order`).
- [ ] Dependency editor → `…/sections/{section}/dependencies` (`depends_on_section_id`, `trigger`, `enforcement`).
- [ ] Availability config → `GET/PUT /v1/teacher/lessons/{lesson}/availability`.
- [ ] Extension inbox → `GET /v1/teacher/extension-requests` + `…/{id}/grant|deny`.

**Student**
- [ ] Lesson page renders `GET /v1/lessons/{lesson}/sections`; respect `locked` (hide/disable mandatory-locked sections; show optional as advisory).
- [ ] Entry confirmation dialog → `POST /v1/lessons/{lesson}/start` (opens window, starts countdown).
- [ ] Live timer polls/derives from `GET /v1/lessons/{lesson}/access` (`remaining_sec`); lock UI when `locked: true`.
- [ ] Post-expiry extension button → `POST /v1/lessons/{lesson}/extension-request`.

## Action plan

1. **BUG-1 first** — deploy latest build to staging + prod target, run migrations, verify columns:
   ```bash
   php artisan migrate --force
   php artisan tinker --execute="echo Schema::hasColumn('media_assets','size_bytes')?'yes':'NO', PHP_EOL, Schema::hasTable('lesson_access_windows')?'yes':'NO';"
   ```
   If 500 persists after migrate, pull the exact `storage/logs/laravel.log` trace and reopen.
2. Confirm which branch/build `ahmedtammam.com` and `front.edu.78sworks.io` run — features may be on an undeployed branch.
3. Hand the wiring checklist to frontend.
4. Re-run QA against the confirmed build.
