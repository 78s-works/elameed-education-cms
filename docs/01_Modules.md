# Elameed Education — Modules

The application is a **modular monolith**. Each bounded context lives under
`app/Modules/<Name>` and owns its own `Http` (Controllers, Requests, Resources,
Middleware), `Models`, `Enums`, `Services`, and — where relevant — `Actions`,
`Jobs`, `Contracts`, `Gateways`, `Providers`, `Observers`, and `Support`. There
is no shared "fat" controller layer (`app/Http/Controllers` holds only the base
`Controller`); routing wires directly to per-module controllers in
[`routes/api.php`](../routes/api.php).

For per-endpoint request/response detail, see the module's file under
[`api/`](api); this document is the map.

---

## Request lifecycle

```
Request
  │
  ├─ (platform-host routes)  no tenant group — auth by token-in-URL / HMAC / signed URL
  │        webhooks/paymob · media/{stream,segment,key} · internal/* · media/callbacks/* · media/upload
  │
  ├─ (/admin/*)  central (EnsureCentralHost, admin-host only → 404 off-host)
  │        → auth:sanctum → admin (EnsurePlatformAdmin)   — NOT tenant-scoped
  │

  └─ (everything else)  tenant group
         EnsureRegisteredDomain   → reject unknown / suspended Host
         ResolveTenant            → bind tenant + RLS session (before model binding)
             └─ auth:sanctum      → bearer token
                 └─ active        → EnsureActiveMembership (non-suspended member)
                     └─ role:X    → EnsureTenantRole (teacher / parent / …)
```

Tenant scoping is enforced two ways: the `ResolveTenant` middleware binds an
**RLS session** before route-model binding, and tenant-owned models use the
`BelongsToTenant` trait (`app/Support/Traits`) for a global query scope. Together
they make cross-tenant reads return **404**, not data.

---

## Roles & identity

Identity is **global** (one `User` can belong to many tenants); authorization is
**per-tenant** via a `TenantUser` membership row.

- **Roles** (`TenantUserRole`): `teacher` · `assistant` · `student` · `parent`
- **Membership status** (`MembershipStatus`): `active` · `pending` · `suspended`
- **Platform admin** is a separate flag on `User` (`isPlatformAdmin()`), not a tenant role.

`GET /me` returns the user, **all** their memberships, and their role in the
**current** tenant.

---

## Module map

| # | Module | PRD ref | Endpoints | Purpose |
|---|---|---|--:|---|
| 1 | [Tenancy](#1-tenancy) | M01 | 9 | Host→tenant resolution, branding, public + teacher landing pages, academy access switches |
| 2 | [Identity](#2-identity) | M02, M11, M13 | 28 | Auth/OTP, `/me`, parent portal, teacher student management |
| 3 | [Catalog](#3-catalog) | M04 | 30 | Course → unit → lesson → attachment hierarchy + categories + packages (bundles) |
| 4 | [Media](#4-media) | M04, M22 | 20 | Protected video: encrypted-HLS pipeline + remote OVH provider, watermarking |
| 5 | [Commerce](#5-commerce) | M05, M06 | 4 | Checkout (quote/order/pay) + Paymob gateway + webhook |
| 6 | [Wallet](#6-wallet) | M05 | 2 | Student wallet balance + append-only ledger (read-only API) |
| 7 | [Centers](#7-centers) | M12 | 11 | Physical branches, activation/recharge codes, attendance, offline sync, code redeem |
| 8 | [Assessment](#8-assessment) | M08 | 13 | Exams/quizzes/assignments: student attempts + teacher authoring & grading |
| 9 | [Engagement](#9-engagement) | M10, M19, M20 | 16 | Reviews, lesson progress, favorites, gamification |
| 10 | [Notifications](#10-notifications) | M10, M14 | 2 | In-app notification feed + SMS-sender abstraction |
| 11 | [Reporting](#11-reporting) | M17, M18 | 4 | Student/teacher summary reports + audit log |
| 12 | [Platform Admin](#12-platform-admin) | M01, M17 | 6 | Cross-tenant operator console (tenants + overview + audit) |
| 13 | [Billing](#13-billing) | M03 | 8 | Teacher subscription packages: admin plan CRUD + tenant assignment + teacher view |

**Total: 146 documented endpoints.**

---

## 1. Tenancy
`app/Modules/Tenancy` — the multi-tenant backbone. Resolves an incoming `Host`
(custom domain or `*.elameed.app` subdomain) to a tenant academy, binds it for
the request, and serves the tenant's public identity/branding plus the
teacher-authored landing page.

- **Models:** `Tenant` (global registry: `uuid`/`slug`/`name`/`status`, soft-deletes), `TenantDomain` (global host→tenant map with Cloudflare SSL fields), `TeacherProfile` (first tenant-scoped model — branding + `landing_sections`/`layout` + the `login_enabled`/`registration_enabled` access switches).
- **Enums:** `TenantStatus` (active/suspended/under_review/expired), `TenantDomainType` (subdomain/custom).
- **Services/Support:** `TenantContext` (request-scoped tenant holder), `TenantResolver` (host/`X-Tenant` → tenant, cached), `LandingResolver`, `LandingSchema` (the `LANDING_CONTRACT_V2` catalog: 10 section types, 3 page layouts, and 4 per-type layout `variant`s per section). Middleware `EnsureRegisteredDomain` + `ResolveTenant` form the `tenant` group.
- **Note:** the public `GET /tenant/landing` returns *resolved* sections (with `items` + an `enrolled` flag under optional auth); the teacher `GET /teacher/landing` returns the raw editable `config` — same row, two shapes. `PUT`s are upserts (forced `200`) and heavily sanitize input.
- **Access switches:** `GET`/`PUT /teacher/access` let a teacher open/close **sign-in** and **self-registration** for their own academy (both default on). Enforced at `POST /auth/login` (when off, only the teacher can still sign in — assistants/students/parents are blocked) and `POST /auth/register` (M11), and surfaced in `GET /tenant/context` → `data.auth` so the SPA can hide the forms.

→ [`api/tenancy.md`](api/tenancy.md)

## 2. Identity
`app/Modules/Identity` — authentication (register, password/OTP login,
forgot/reset), the `/me` endpoint, the read-only parent portal, and the
teacher's full student-management surface (roster CRUD, enrollments, wallet,
activity, notifications, parent linking).

- **Models:** `TenantUser` (membership + role, global), `StudentProfile` (per-academy registration fields), `ParentLink`, `OtpCode` (hash-only), `LoginAttempt` (create-only audit). Authenticates the shared global `User`.
- **Actions/Services:** `RegisterStudentAction`, `LoginAction`, `VerifyOtpAction`, `ResetPasswordAction`; `OtpService` + `SendOtpJob`; `UserLookup` (phone-or-email). Middleware `EnsureTenantRole` (`role:`) + `EnsureActiveMembership` (`active`).
- **Enums:** `TenantUserRole`, `MembershipStatus`, `OtpPurpose` (register/login/reset).
- **Notes / gotchas:** foreign-tenant students return **404** (invisible, not forbidden); `register` → `202`; `/me`'s `current.permissions` is a `[]` placeholder (P1.5); **OTP verify is currently stubbed** (`OtpService::verify()` returns `true` — real logic pending). Wallet adjust/set post balanced double-entry ledger legs and are audit-logged.

→ [`api/identity.md`](api/identity.md)

## 3. Catalog
`app/Modules/Catalog` — the tenant's course library: a public storefront
(browse/detail) plus teacher authoring of the `course → unit → lesson →
attachment` hierarchy, the category taxonomy, and **packages** (bundles that
group courses/units into one sellable product).

- **Models:** `CourseCategory`, `Course`, `Unit`, `Lesson`, `Bundle`, `BundleItem`. **Attachments are not a dedicated model** — they are Media's `MediaAsset` rows (type `pdf`/`file`/`link`) linked by `lesson_id`; the lesson's `hls_video` asset is excluded from the attachments list.
- **Enums:** `ContentVisibility` (`visible`/`hidden`/`scheduled`).
- **Packages (bundles):** a `Bundle` has many `BundleItem`s, each a `course`, `unit`, or `lesson`. Teacher CRUD at `/teacher/bundles` (items set inline as an `items` array); public browse at `/bundles`. Buying one (Commerce `bundle` item) grants an enrollment per item — a course item opens the whole course (lessons + exams), a unit item opens that chapter's lessons, a lesson item opens just that lesson. The package's `access_days` sets the window.
- **Notes / gotchas:** binding keys differ — courses and bundles bind by `slug` (public) vs `uuid` (teacher); units/lessons by numeric `id`; attachments by `uuid`. "Published" = `visibility == visible` AND (`publish_at` null or past). Slug is server-generated and immutable. Public list is fixed at 20/page, newest-first (no `sort`/`per_page`); filters: `filter[category_id|grade|subject]`, `q`.

→ [`api/catalog.md`](api/catalog.md)

## 4. Media
`app/Modules/Media` — protected video. A self-hosted **encrypted-HLS** pipeline
(upload → per-viewer AES-128 transcode with burned-in watermark → token-gated
playback) plus an interchangeable **remote OVH "Media Host"** provider, switched
by `MEDIA_PROVIDER` (`local` default / `remote`).

- **Models:** `MediaAsset` (video/pdf/file/link, tenant-scoped), `MediaVersion` (remote host versions), `MediaUploadSession` (idempotent upload intents), `MediaRendition` (per-student encrypted HLS, `enc_key` encrypted at rest), `PlaybackSession` (short-lived hashed token grants), `MediaCallbackEvent` (replay ledger, deliberately not tenant-scoped).
- **Enums / states:** `MediaStatus` (`uploading→transcoding→ready|failed`), `MediaType` (`hls_video|pdf|file|link`), `MediaVersionState` (guarded state machine: `pending→…→ready`, plus `quarantined`/`restore`/`purged`).
- **Notes / gotchas:** **dual auth** — platform-host endpoints (key/stream/segment, callbacks, signed upload) run **outside** the tenant group, authed by token-in-URL / HMAC (`X-Media-Signature` + `X-Media-Event-Id`) / shared secret (`X-Transcode-Secret`). **Non-JSON responses**: `stream`→m3u8, `segment`→binary `.ts`, `key`→raw AES bytes, `authz`→`204`/`403`. The raw source is never served; the key endpoint re-checks access before releasing the key. Remote endpoints throw `409` when `MEDIA_PROVIDER≠remote` (never a silent fallback).

→ [`api/media.md`](api/media.md)

## 5. Commerce
`app/Modules/Commerce` — the checkout pipeline (quote → order → pay), the Paymob
gateway adapter, and its confirmation webhook. Wallet payment fulfils inline;
Paymob returns a hosted `redirect_url` and completes asynchronously.

- **Models:** `Order`, `OrderItem`, `Payment`, `Enrollment`, `Invoice`. Services `CheckoutService`, `FulfillOrderService`, `EnrollmentService`, `InvoiceService`; `PaymobGateway` implements `Contracts\PaymentGateway`.
- **Enums:** `OrderStatus` (pending/paid/failed/refunded), Payment status consts (pending/paid/failed), `EnrollmentStatus` (active/expired/cancelled), `EnrollmentSource` (purchase/wallet/code/manual/center).
- **Item types:** cart items are `course`, `bundle` (package), or `wallet_topup`. `EnrollmentService` grants a whole-**course**, a **unit**, or a single-**lesson** enrollment (the last two from a package); `hasAccess` (course) and `hasLessonAccess` (course-or-unit-or-lesson) are the read side. Fulfilling a `bundle` grants an enrollment per contained course/unit/lesson in one transaction (idempotent), using the package's `access_days`.
- **Notes / gotchas:** `Idempotency-Key` header is **accepted but ignored in P1** — idempotency is server-side (pay short-circuits paid orders; webhook dedupes on `gateway_txn_id`; fulfilment dedupes on the ledger op-key). Paymob is a **P1 stub** (placeholder redirect URL). Webhook is **outside** the tenant group (tenant derived from the order); signature header **`X-Paymob-Hmac`** (HMAC-SHA512), `throttle:120,1`.

→ [`api/commerce.md`](api/commerce.md)

## 6. Wallet
`app/Modules/Wallet` — each student's wallet and its append-only double-entry
ledger. Read-only over the API (writes originate from Commerce, Centers, and
teacher adjustments).

- **Models:** `Wallet` (balance **derived**, never stored), `LedgerEntry` (append-only, no `updated_at`). Service `LedgerService` is the single money-writer, posting balanced legs with a unique `idempotency_key`.
- **Accounts:** `student_wallet` · `teacher_earnings` · `platform_commission` · `gateway_clearing`. **Directions:** `debit` · `credit`.

→ [`api/wallet.md`](api/wallet.md)

## 7. Centers
`app/Modules/Centers` — physical learning-center branches, one-time
activation/recharge codes, attendance, and offline-device sync. Also owns the
student-facing `POST /codes/redeem`.

- **Models:** `Center` (uuid), `ActivationCode` (uuid), `AttendanceRecord` (integer id; one row per student/center/day, deduped on a unique key + `external_ref`).
- **Enums:** `CodeType` (`wallet` credits `amount_minor` / `course` enrolls in `course_id`), `CodeStatus` (`active`/`redeemed`/`disabled`).
- **Notes / gotchas:** redeem is atomic + one-time (`lockForUpdate`, ledger op-key `code:{uuid}`); all redeem failures come back as **422** on the `code` field. Sync ingests `events[]` (1–500) with per-item `external_ref` idempotency and returns `200` with per-item `applied`/`duplicate`/`failed`. Pagination is inconsistent: centers list is not paginated; codes/attendance are (50/page).

→ [`api/centers.md`](api/centers.md)

## 8. Assessment
`app/Modules/Assessment` — one `Exam` model powers exams, quizzes, and
assignments. Students discover → start → submit → view results; teachers author
exams + questions and hand-grade subjective answers.

- **Models:** `Exam` (uuid, soft-deletes), `Question` (`correct` is `$hidden`), `ExamAttempt` (bound by `id`, JSON `answers` map).
- **Enums:** `QuestionType` (`mcq`/`true_false` auto-graded · `short`/`essay`/`file` manual), `ExamType` (`exam`/`assignment`), `ExamMode` (`standard`/`bubble_sheet`), `AttemptStatus` (`in_progress→submitted→graded`).
- **Notes / gotchas:** answer-key leak protection — `PublicQuestionResource` (student) omits `correct`; `QuestionResource` (teacher) includes it. Objective questions auto-grade on submit; any subjective question sets `needs_manual_grade` and holds at `submitted`. `result_visibility` (`immediate`/`after_close`/`manual`) controls what the student sees. `attempts_allowed = 0` = unlimited; `start` resumes an open attempt.

→ [`api/assessment.md`](api/assessment.md)

## 9. Engagement
`app/Modules/Engagement` — the learner's post-enrollment surface: gated course
reviews, lesson-progress tracking (resume/activity), favorites, and gamification
(points/badges/leaderboard) plus teacher badge management.

- **Models:** `Review`, `LessonProgress` (table `lesson_progress`), `Favorite`, `PointsEntry` (append-only), `Badge`, `StudentBadge` (uses `awarded_at`). Service `PointsService`.
- **Values:** `rating` 1–5; completion at `watch_percent >= 95`; per-event points from `config/gamification.php` (`lesson_points`, `exam_points`).
- **Notes / gotchas:** review store is an **upsert** gated on course access (`403` otherwise), returns `201` even on update. Progress never regresses (`max(existing, incoming)`); crossing 95% awards points once (idempotent). Favorites are idempotent (`firstOrCreate`, always `201`; missing course → `422`). Leaderboard honours the teacher's `hide_ranking` toggle → `{ hidden: true, entries: [] }`.

→ [`api/engagement.md`](api/engagement.md)

## 10. Notifications
`app/Modules/Notifications` — a per-user in-app notification feed plus an
SMS-sender abstraction.

- **Models:** `Notification` (tenant-scoped: `channel`/`type`/`payload`/`status`/`sent_at`/`read_at`). Services: `NotificationService`, `SmsSender` contract with a `LogSmsSender` driver.
- **Endpoints:** list the current user's notifications; mark one read.

→ [`api/notifications.md`](api/notifications.md)

## 11. Reporting
`app/Modules/Reporting` — read-only student/teacher summaries and the audit
trail. The report endpoints read from other modules' models; the module owns
only the audit log.

- **Models:** `AuditLog` (append-only, `UPDATED_AT = null`, nullable `tenant_id`).
- **Notes / gotchas:** teacher `sales`/`students` reports are **all-time** aggregates (no `?from=&to=` in P1). Audit log is one controller with two scopes — teacher forces the current `tenant_id`; admin filters by optional `?tenant=` and can see nullable-tenant rows. `GET /me/courses` returns a plain array and preserves an existing API key typo: **`watch_precent`** (sic).

→ [`api/reporting.md`](api/reporting.md)

## 12. Platform Admin
`app/Modules/PlatformAdmin` — the cross-tenant operator console. **Not**
tenant-scoped: `/v1/admin/*` sits outside the `tenant` group and uses
`auth:sanctum` + `admin` (`EnsurePlatformAdmin` → `403` for non-admins).

- **Models:** none of its own (`Tenant` lives in Tenancy); contributes `AdminTenantResource`, `Store/UpdateTenantRequest`, the `EnsurePlatformAdmin` middleware, and (Task 1) `EnsureCentralHost` (`central`) which host-pins the console.
- **Endpoints:** tenants index/store/show/update, `reports/overview`, admin `audit-logs`. Tenant targeting is via `{tenant:uuid}` path or `?tenant=` query — never a header.
- **Host isolation:** `/admin/*` is served **only** on a central/admin host — off-host requests (e.g. a teacher's domain) get `404` before auth. A platform-admin token also carries **no** access to tenant-scoped routes (the `role`/`active` gates give admins no bypass). `StoreTenantRequest` rejects reserved slugs so a tenant subdomain can't shadow a central host.

→ [`api/platform-admin.md`](api/platform-admin.md)

## 13. Billing
`app/Modules/Billing` — teacher subscription packages (M03), the platform's
recurring-revenue layer. The admin defines plans and assigns them to academies;
each teacher sees their own plan, limits, and usage.

- **Models:** `SubscriptionPackage` (global, soft-deletes: price/interval/trial/`limits` JSON/`is_active`), `TenantSubscription` (global, `tenant_id` but not RLS-scoped: locked `price_minor` + lifecycle `status`). `tenants.package_id` FK points at the current plan.
- **Enums:** `SubscriptionStatus` (`trialing`/`active`/`past_due`/`canceled`/`expired`), `BillingInterval` (`monthly`/`yearly`).
- **Services:** `SubscriptionService` (assign supersedes the prior plan + syncs `tenants.package_id`; `current()`), `PackageUsage` (usage-vs-limits snapshot).
- **Endpoints:** admin `packages` index/store/show/update/destroy (destroy = soft retire) + `tenants/{uuid}/subscription` show/store (assign, with discount/trial overrides); teacher `GET /teacher/subscription`. Admin routes share the host-pinned `/admin/*` group; the teacher route is tenant-scoped `role:teacher`.
- **Notes / gotchas:** `limits` keys `max_students|max_courses|storage_mb|max_assistants`, `null` = unlimited; limits are **reported, not yet enforced** at create paths (follow-up), and `storage_mb.used` is `0` until media byte-counting lands. Packages/tenants bind by `uuid`. Both tables are **global** (admin-managed cross-tenant; teacher reads via explicit `tenant_id`).

→ [`api/billing.md`](api/billing.md)
