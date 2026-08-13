<?php

use App\Modules\Assessment\Http\Controllers\AttemptController;
use App\Modules\Assessment\Http\Controllers\Teacher\BubbleSheetController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamExtensionRequestController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamGradingController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamLinkController;
use App\Modules\Assessment\Http\Controllers\Teacher\QuestionController;
use App\Modules\Billing\Http\Controllers\Admin\PackageController;
use App\Modules\Billing\Http\Controllers\Admin\TenantSubscriptionController;
use App\Modules\Billing\Http\Controllers\Teacher\PackageController as TeacherPackageController;
use App\Modules\Billing\Http\Controllers\Teacher\SubscriptionController;
use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Catalog\Http\Controllers\StudentLessonAccessController;
use App\Modules\Catalog\Http\Controllers\StudentLessonSectionsController;
use App\Modules\Catalog\Http\Controllers\StudentLibraryController;
use App\Modules\Catalog\Http\Controllers\Teacher\AcademicYearController;
use App\Modules\Catalog\Http\Controllers\Teacher\CategoryController;
use App\Modules\Catalog\Http\Controllers\Teacher\ContentPackageController;
use App\Modules\Catalog\Http\Controllers\Teacher\CourseListController;
use App\Modules\Catalog\Http\Controllers\Teacher\ExtensionRequestController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonAttachmentController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonAvailabilityController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonSectionController;
use App\Modules\Catalog\Http\Controllers\Teacher\PackageTypeController;
use App\Modules\Centers\Http\Controllers\RedeemCodeController;
use App\Modules\Centers\Http\Controllers\StudentCenterExamGradeController;
use App\Modules\Centers\Http\Controllers\Teacher\ActivationCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\AttendanceController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterExamGradeController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterIdCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterSyncController;
use App\Modules\Commerce\Http\Controllers\CheckoutController;
use App\Modules\Commerce\Http\Controllers\InvoiceController;
use App\Modules\Commerce\Http\Controllers\PaymentWebhookController;
use App\Modules\Commerce\Http\Controllers\Teacher\CouponController;
use App\Modules\Engagement\Http\Controllers\AttachmentController;
use App\Modules\Engagement\Http\Controllers\CommentController;
use App\Modules\Engagement\Http\Controllers\FavoriteController;
use App\Modules\Engagement\Http\Controllers\GamificationController;
use App\Modules\Engagement\Http\Controllers\ProgressController;
use App\Modules\Engagement\Http\Controllers\ReviewController;
use App\Modules\Engagement\Http\Controllers\SupportTicketController;
use App\Modules\Engagement\Http\Controllers\Teacher\BadgeController;
use App\Modules\Engagement\Http\Controllers\Teacher\ForumController;
use App\Modules\Engagement\Http\Controllers\Teacher\SupportTicketController as TeacherSupportTicketController;
use App\Modules\Engagement\Http\Controllers\Teacher\ReviewController as TeacherReviewController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\ParentController;
use App\Modules\Identity\Http\Controllers\Teacher\AssistantController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentActivityController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentContentOverrideController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentEnrollmentController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentFinanceController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentImportController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentParentController;
use App\Modules\Media\Http\Controllers\InternalMediaController;
use App\Modules\Media\Http\Controllers\MediaCallbackController;
use App\Modules\Media\Http\Controllers\PlaybackController;
use App\Modules\Media\Http\Controllers\RemotePlaybackController;
use App\Modules\Media\Http\Controllers\Teacher\RemoteVideoController;
use App\Modules\Media\Http\Controllers\TeacherMediaController;
use App\Modules\Notifications\Http\Controllers\Admin\EventController as AdminNotificationEventController;
use App\Modules\Notifications\Http\Controllers\Admin\TemplateController as AdminNotificationTemplateController;
use App\Modules\Notifications\Http\Controllers\Admin\TranslationController as AdminNotificationTranslationController;
use App\Modules\Notifications\Http\Controllers\Admin\TypeController as AdminNotificationTypeController;
use App\Modules\Notifications\Http\Controllers\InboxController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\Teacher\SmsSettingsController;
use App\Modules\Notifications\Http\Controllers\Teacher\TeacherNotificationController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminReportController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminTenantController;
use App\Modules\Reporting\Http\Controllers\AuditLogController;
use App\Modules\Reporting\Http\Controllers\StudentCoursesController;
use App\Modules\Reporting\Http\Controllers\TeacherReportsController;
use App\Modules\Tenancy\Http\Controllers\Teacher\DomainController;
use App\Modules\Tenancy\Http\Controllers\TeacherCustomLandingController;
use App\Modules\Tenancy\Http\Controllers\TeacherLandingController;
use App\Modules\Tenancy\Http\Controllers\TeacherMetaController;
use App\Modules\Tenancy\Http\Controllers\TeacherProfileController;
use App\Modules\Tenancy\Http\Controllers\TenantAccessController;
use App\Modules\Tenancy\Http\Controllers\TenantContextController;
use App\Modules\Tenancy\Http\Controllers\TenantLandingController;
use App\Modules\Tenancy\Http\Controllers\TenantLandingMetaController;
use App\Modules\Wallet\Http\Controllers\Teacher\PaymentReceiptController;
use App\Modules\Wallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform-host webhooks (no tenant resolution from host)
|--------------------------------------------------------------------------
| The tenant is derived from the referenced order, not the Host header, so
| these run outside the `tenant` middleware group.
*/

Route::prefix('v1')->group(function (): void {
    Route::post('/webhooks/paymob', [PaymentWebhookController::class, 'paymob'])->middleware('throttle:120,1');

    // AES key (token-authenticated) + internal media-tier endpoints.
    Route::get('/media/key/{token}', [PlaybackController::class, 'key']);
    Route::get('/internal/media/authz', [InternalMediaController::class, 'authz']);
    Route::post('/internal/transcode/callback', [InternalMediaController::class, 'transcodeCallback']);

    // Remote Media Host processing callback (OVH). No tenant/bearer — the HMAC
    // signature (X-Media-Signature) is the auth; replay-protected by X-Media-Event-Id.
    Route::post('/media/callbacks/processing', [MediaCallbackController::class, 'processing'])->middleware('throttle:120,1');

    // Token-gated encrypted-HLS delivery. The token is carried in the URL (a
    // <video>/hls.js request can't send headers); segments are AES-128 encrypted
    // and the key endpoint re-checks access before releasing the key. The raw
    // source is never exposed — it lives on a private disk.
    Route::get('/media/stream/{token}', [PlaybackController::class, 'stream']);
    Route::get('/media/segment/{token}/{segment}', [PlaybackController::class, 'segment'])->where('segment', 'seg_[0-9]+\.ts');

    // Local dev upload receiver for the async pipeline: the client PUTs the raw
    // file (or multipart `file`) to the signed `upload_url` from startUpload. The
    // signature is the auth (no tenant/bearer needed); prod uses a real object-
    // storage presigned target instead of this route.
    Route::match(['put', 'post'], '/media/upload/{uuid}', [TeacherMediaController::class, 'receiveUpload'])
        ->middleware('signed')
        ->name('media.upload.receive');
});

/*
|--------------------------------------------------------------------------
| Platform admin (M01, M17) — cross-tenant, NOT tenant-scoped
|--------------------------------------------------------------------------
|
| Served ONLY on a central/admin host (`central` middleware = EnsureCentralHost):
| a teacher academy's subdomain or custom domain answers /admin/* with 404, so
| the console can never be opened from a teacher's domain even with a valid
| platform-admin token. The host check runs before auth. See docs/api/platform-admin.md.
*/
Route::prefix('v1')->middleware(['central', 'auth:sanctum', 'admin'])->group(function (): void {
    Route::get('/admin/tenants', [AdminTenantController::class, 'index']);
    Route::post('/admin/tenants', [AdminTenantController::class, 'store']);
    Route::get('/admin/tenants/{tenant:uuid}', [AdminTenantController::class, 'show']);
    Route::put('/admin/tenants/{tenant:uuid}', [AdminTenantController::class, 'update']);
    Route::get('/admin/reports/overview', [AdminReportController::class, 'overview']);
    Route::get('/admin/audit-logs', [AuditLogController::class, 'admin']);

    // Teacher subscription packages (M03) — define plans + assign them to tenants.
    Route::get('/admin/packages', [PackageController::class, 'index']);
    Route::post('/admin/packages', [PackageController::class, 'store']);
    Route::get('/admin/packages/{package:uuid}', [PackageController::class, 'show']);
    Route::put('/admin/packages/{package:uuid}', [PackageController::class, 'update']);
    Route::delete('/admin/packages/{package:uuid}', [PackageController::class, 'destroy']);

    Route::get('/admin/tenants/{tenant:uuid}/subscription', [TenantSubscriptionController::class, 'show']);
    Route::post('/admin/tenants/{tenant:uuid}/subscription', [TenantSubscriptionController::class, 'store']);

    // Notification engine (doc 10 §9.1) — system scope: author the type catalog,
    // system templates, and translations; audit dispatched events. Types bind by
    // `key` (dotted module.entity.event); templates addressed by {type}/{channel}.
    Route::get('/admin/notifications/types', [AdminNotificationTypeController::class, 'index']);
    Route::post('/admin/notifications/types', [AdminNotificationTypeController::class, 'store']);
    Route::get('/admin/notifications/types/{type:key}', [AdminNotificationTypeController::class, 'show']);
    Route::put('/admin/notifications/types/{type:key}', [AdminNotificationTypeController::class, 'update']);
    Route::delete('/admin/notifications/types/{type:key}', [AdminNotificationTypeController::class, 'destroy']);

    Route::get('/admin/notifications/types/{type:key}/templates', [AdminNotificationTemplateController::class, 'index']);
    Route::post('/admin/notifications/types/{type:key}/templates', [AdminNotificationTemplateController::class, 'store']);

    Route::put('/admin/notifications/types/{type:key}/templates/{channel}/translations', [AdminNotificationTranslationController::class, 'upsert']);
    Route::delete('/admin/notifications/types/{type:key}/templates/{channel}/translations/{language}', [AdminNotificationTranslationController::class, 'destroy']);

    Route::get('/admin/notifications/events', [AdminNotificationEventController::class, 'index']);
    Route::get('/admin/notifications/events/{event}', [AdminNotificationEventController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| URI-versioned (04_API_Specification.md §1). Every request runs through the
| `tenant` middleware so the tenant is resolved and the RLS session bound
| before any tenant-scoped work.
|
*/

Route::prefix('v1')->middleware('tenant')->group(function (): void {

    // Tenant context & branding. Public + high-traffic (SPA boot) → throttled per IP.
    Route::get('/tenant/context', TenantContextController::class)->middleware('throttle:public');
    // Public landing page (resolved: layout + nav + sections). Optional auth → `enrolled`.
    Route::get('/tenant/landing', TenantLandingController::class)->middleware('throttle:public');
    // Public landing bundle: branding + teacher site metadata (SEO/OG) for the <head>.
    Route::get('/tenant/landing/meta', TenantLandingMetaController::class)->middleware('throttle:public');

    // Public catalogue (M04) — published courses of the resolved tenant
    Route::get('/courses', [PublicCatalogController::class, 'index']);
    Route::get('/courses/{course:slug}', [PublicCatalogController::class, 'show']);
    Route::get('/courses/{course:slug}/reviews', [ReviewController::class, 'index']);

    // Identity, auth & OTP (M11) — public
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:otp');
    Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:otp');

    // Passwordless parent access (M13/VD R11) — public: a permanent magic-link
    // token mints a parent session. Rate-limited per IP (auth-class) against
    // token guessing; the token is hashed at rest and tenant-scoped.
    Route::get('/parent/magic/{token}', [ParentController::class, 'magicLogin'])->middleware('throttle:auth');

    // Authenticated — must be an ACTIVE member of this tenant (suspend blocks here).
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', MeController::class);

        // Course reviews — a student with access rates their course (upsert)
        Route::post('/courses/{course:slug}/reviews', [ReviewController::class, 'store']);

        // Redeem an activation/recharge code (M12) → wallet credit or course enroll
        Route::post('/codes/redeem', RedeemCodeController::class);

        // Wallet, checkout & payments (M05, M06)
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);
        // Manual top-up (VD R9) — submit a Vodafone Cash / InstaPay receipt → pending.
        Route::post('/wallet/topup/manual', [WalletController::class, 'topupManual'])->middleware('throttle:60,1');
        // The student's own manual top-up receipts + status (VD F3). Read-only.
        Route::get('/wallet/topups', [WalletController::class, 'topups']);
        Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('/checkout/order', [CheckoutController::class, 'order']);
        Route::post('/checkout/pay', [CheckoutController::class, 'pay'])->middleware('throttle:auth');
        // Validate a promo code against a cart without ordering (M21).
        Route::post('/coupons/validate', [CheckoutController::class, 'validateCoupon']);

        // Invoices (M06) — the buyer's own invoices + access-controlled PDF download.
        // A tenant teacher/assistant may also read/download any invoice in their academy.
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice:uuid}', [InvoiceController::class, 'show']);
        Route::get('/invoices/{invoice:uuid}/download', [InvoiceController::class, 'download']);

        // Protected playback (M04, M22) — authorize issues a short-lived token.
        Route::post('/media/lessons/{lesson}/playback', [PlaybackController::class, 'authorize']);
        // Remote (OVH Media Host) playback authorization — active when MEDIA_PROVIDER=remote.
        Route::post('/media/remote/lessons/{lesson}/playback', [RemotePlaybackController::class, 'authorize']);

        // Progress (M10, M20)
        Route::post('/lessons/{lesson}/progress', [ProgressController::class, 'store']);
        Route::get('/me/activity', [ProgressController::class, 'activity']);
        Route::get('/me/resume', [ProgressController::class, 'resume']);

        // Lesson meta for the lesson-native player (title/duration/completion) —
        // content is in /sections; this is the header without a parent course.
        Route::get('/lessons/{lesson}', [StudentLessonSectionsController::class, 'show']);

        // Typed content sections + unlock state (M04, FR-M04-01/06). Each section
        // carries a `locked` flag from the mandatory content-dependency rules.
        Route::get('/lessons/{lesson}/sections', [StudentLessonSectionsController::class, 'index']);

        // Lesson availability window + countdown (M04). `start` opens the time-box
        // (confirm dialog), `access` feeds the countdown timer, and a student may
        // request an extension after expiry.
        Route::post('/lessons/{lesson}/start', [StudentLessonAccessController::class, 'start']);
        Route::get('/lessons/{lesson}/access', [StudentLessonAccessController::class, 'access']);
        // Auto self-reopen (VD R3/R4) — instant 24h extend up to self_reopen_limit;
        // 409 reopen_limit_reached past it, then the extension-request path below.
        Route::post('/lessons/{lesson}/reopen', [StudentLessonAccessController::class, 'reopen']);
        Route::post('/lessons/{lesson}/extension-request', [StudentLessonAccessController::class, 'requestExtension']);

        // Q&A comments + polymorphic attachments (M09). Shared by students (need
        // lesson access) and staff; {lesson} binds by id, {comment} by uuid.
        Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:60,1');
        Route::get('/lessons/{lesson}/comments', [CommentController::class, 'index']);
        Route::post('/lessons/{lesson}/comments', [CommentController::class, 'store']);
        Route::post('/comments/{comment}/replies', [CommentController::class, 'reply']);

        // Support tickets (M09, B24 / VD Item 11) — a student opens a ticket to
        // teacher/assistant (subject + message + attachments + priority), lists
        // their own, and reads a single thread with replies. {ticket} binds by
        // uuid and is scoped to the caller in the controller.
        Route::get('/support/tickets', [SupportTicketController::class, 'index']);
        Route::post('/support/tickets', [SupportTicketController::class, 'store']);
        Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);

        // Favorites (M20)
        Route::get('/me/favorites', [FavoriteController::class, 'index']);
        Route::post('/me/favorites', [FavoriteController::class, 'store']);
        Route::delete('/me/favorites/{course:uuid}', [FavoriteController::class, 'destroy']);

        // Gamification (M19)
        Route::get('/me/points', [GamificationController::class, 'points']);
        Route::get('/me/badges', [GamificationController::class, 'badges']);
        Route::get('/leaderboard', [GamificationController::class, 'leaderboard']);

        // Notifications (M10, M14)
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::post('/me/notifications/{notification}/read', [NotificationController::class, 'read']);

        // Engine inbox (doc 10 §7) — in-app `database` notifications from the
        // notification engine (new_notifications). Separate from the legacy
        // /me/notifications above; both are read-only student surfaces.
        Route::get('/me/inbox', [InboxController::class, 'index']);
        Route::get('/me/inbox/unread-count', [InboxController::class, 'unreadCount']);
        Route::post('/me/inbox/read-all', [InboxController::class, 'readAll']);
        Route::post('/me/inbox/{message}/read', [InboxController::class, 'read']);

        // Exams & assignments — student side (M08)
        Route::get('/exams', [AttemptController::class, 'index']);
        Route::post('/exams/{exam:uuid}/attempts', [AttemptController::class, 'start']);
        Route::post('/exams/{exam:uuid}/attempts/{attempt}/files', [AttemptController::class, 'uploadFile']);
        Route::post('/exams/{exam:uuid}/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
        Route::get('/exams/{exam:uuid}/attempts/{attempt}', [AttemptController::class, 'result']);
        // Download the teacher's corrected/annotated file for the student's attempt.
        Route::get('/exams/{exam:uuid}/attempts/{attempt}/corrected-file', [AttemptController::class, 'downloadCorrectedFile']);
        // Student asks for extra time on an exam/quiz (doc 11 R6).
        Route::post('/exams/{exam:uuid}/extension-request', [AttemptController::class, 'requestExtension']);

        Route::get('/me/courses', [StudentCoursesController::class, 'index']);
        // The student's own library (VD F1): purchased standalone lessons + packages.
        Route::get('/me/lessons', [StudentLibraryController::class, 'lessons']);
        Route::get('/me/packages', [StudentLibraryController::class, 'packages']);
        // One bought package's playable lessons (recursive descendants ∩ owned) —
        // the only student-facing package-contents surface.
        Route::get('/me/packages/{package}/lessons', [StudentLibraryController::class, 'packageLessons']);

        // Own paper (in-center) exam scores (VD R12). Spans academic years — no
        // X-Academic-Year required; still tenant-scoped.
        Route::get('/me/center-exam-grades', StudentCenterExamGradeController::class);

        // Parent portal (M13) — parent role in the current tenant
        Route::middleware('role:parent')->group(function (): void {
            Route::get('/parent/children', [ParentController::class, 'children']);
            // Multi-child switcher (VD R11) — set the active child of this session.
            Route::post('/parent/switch', [ParentController::class, 'switchChild']);
            Route::get('/parent/children/{student:uuid}/progress', [ParentController::class, 'progress']);
            Route::get('/parent/children/{student:uuid}/results', [ParentController::class, 'results']);
        });

        // Teacher site & identity (M02) — teacher role in the current tenant
        Route::middleware('role:teacher')->group(function (): void {
            Route::get('/teacher/profile', [TeacherProfileController::class, 'show']);
            Route::put('/teacher/profile', [TeacherProfileController::class, 'update']);

            // Access switches (M02) — open/close sign-in + self-registration for
            // this academy. Enforced at /auth/login + /auth/register (M11).
            Route::get('/teacher/access', [TenantAccessController::class, 'show']);
            Route::put('/teacher/access', [TenantAccessController::class, 'update']);

            // Custom-landing switch (M02) — ON = SPA renders its own bundled
            // custom/<slug>/ page; OFF (default) = the CMS landing sections.
            // Mirrored in GET /tenant/context → data.landing.custom_enabled.
            Route::get('/teacher/custom-landing', [TeacherCustomLandingController::class, 'show']);
            Route::put('/teacher/custom-landing', [TeacherCustomLandingController::class, 'update']);

            // SMS settings (M10) — teacher stores his own WE Business SMS
            // (Connekio) credentials; SMS only works for the tenant once set +
            // enabled. Password is write-only; stored encrypted per tenant.
            Route::get('/teacher/sms-settings', [SmsSettingsController::class, 'show']);
            Route::put('/teacher/sms-settings', [SmsSettingsController::class, 'update']);

            // Custom domains (M02) — attach the academy's own domain. The host
            // resolves to this tenant once the DNS record is set; the auto
            // subdomain stays read-only. {domain} is a uuid, scoped in-controller.
            Route::get('/teacher/domains', [DomainController::class, 'index']);
            Route::post('/teacher/domains', [DomainController::class, 'store']);
            Route::post('/teacher/domains/{domain}/primary', [DomainController::class, 'setPrimary']);
            Route::delete('/teacher/domains/{domain}', [DomainController::class, 'destroy']);

            // Teacher subscription (M03) — read-only view of the tenant's plan,
            // limits, and usage. The plan is managed by the platform admin.
            Route::get('/teacher/subscription', [SubscriptionController::class, 'show']);
            // Available plans to compare (each flagged is_current). Read-only —
            // switching is admin-driven (see docs/api/billing.md).
            Route::get('/teacher/packages', [TeacherPackageController::class, 'index']);
            Route::get('/teacher/landing', [TeacherLandingController::class, 'show']);
            Route::put('/teacher/landing', [TeacherLandingController::class, 'update']);
            Route::post('/teacher/landing/media', [TeacherLandingController::class, 'media']);

            // Site metadata (M02) — teacher-managed key/value entries (SEO tags,
            // custom head data, …), namespaced by `group`. Separate from the
            // landing/profile config; bound by id and tenant-scoped.
            Route::get('/teacher/meta', [TeacherMetaController::class, 'index']);
            Route::post('/teacher/meta', [TeacherMetaController::class, 'store']);
            Route::get('/teacher/meta/{meta}', [TeacherMetaController::class, 'show']);
            Route::put('/teacher/meta/{meta}', [TeacherMetaController::class, 'update']);
            Route::delete('/teacher/meta/{meta}', [TeacherMetaController::class, 'destroy']);

            // Reviews & landing testimonials (M20) — teacher-panel CRUD: moderate
            // student reviews (hide/show/edit/delete) + author curated testimonials.
            Route::get('/teacher/reviews', [TeacherReviewController::class, 'index']);
            Route::post('/teacher/reviews', [TeacherReviewController::class, 'store']);
            Route::get('/teacher/reviews/{review}', [TeacherReviewController::class, 'show']);
            Route::put('/teacher/reviews/{review}', [TeacherReviewController::class, 'update']);
            Route::delete('/teacher/reviews/{review}', [TeacherReviewController::class, 'destroy']);

            // Academic years (VD change set) — top-level content containers.
            // Bind by uuid; NOT behind the `academic-year` middleware (this is
            // where years are managed, so no year context is needed).
            Route::get('/teacher/academic-years', [AcademicYearController::class, 'index']);
            Route::post('/teacher/academic-years', [AcademicYearController::class, 'store']);
            Route::get('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'show']);
            Route::put('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'update']);
            Route::delete('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'destroy']);

            // Read-only course list (VD): teacher course CRUD retired (managed via
            // lessons/packages now), but courses still exist as the public catalogue
            // unit and several features still scope to them (coupons, reviews,
            // center activation-codes). This lister is their picker source — no
            // create/update/delete.
            Route::get('/teacher/courses', [CourseListController::class, 'index']);

            // Catalog (M04) — course taxonomy + structure. Courses bind by uuid
            // (no id enumeration); nested units/lessons bind by id (own data).
            Route::get('/teacher/categories', [CategoryController::class, 'index']);
            Route::post('/teacher/categories', [CategoryController::class, 'store']);
            Route::put('/teacher/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/teacher/categories/{category}', [CategoryController::class, 'destroy']);

            // Standalone lessons + their parts (VD change set §7/§8, doc 13 Phase
            // 3). Year-scoped: every request carries X-Academic-Year (academic-year
            // middleware); {lesson}/{section} bind by id within the active year, so
            // a lesson from another year (or tenant) 404s.
            Route::middleware('academic-year')->group(function (): void {
                Route::get('/teacher/lessons', [LessonController::class, 'index']);
                Route::post('/teacher/lessons', [LessonController::class, 'store']);
                Route::get('/teacher/lessons/{lesson}', [LessonController::class, 'show']);
                Route::put('/teacher/lessons/{lesson}', [LessonController::class, 'update']);
                Route::delete('/teacher/lessons/{lesson}', [LessonController::class, 'destroy']);

                // Parts (reuse lesson_sections). `reorder` is registered before the
                // `{section}` route so the literal path isn't captured as an id.
                Route::get('/teacher/lessons/{lesson}/sections', [LessonSectionController::class, 'index']);
                Route::post('/teacher/lessons/{lesson}/sections', [LessonSectionController::class, 'store']);
                Route::put('/teacher/lessons/{lesson}/sections/reorder', [LessonSectionController::class, 'reorder']);
                Route::put('/teacher/lessons/{lesson}/sections/{section}', [LessonSectionController::class, 'update']);
                Route::delete('/teacher/lessons/{lesson}/sections/{section}', [LessonSectionController::class, 'destroy']);

                // Recursive content packages (VD change set §8.4, doc 13 Phase 5).
                // Base path `content-packages` — `/teacher/packages` is Billing's
                // subscription plans (D13-1). {package}/{item} bind by id within
                // the active year. `items/reorder` is registered before the
                // `{item}` route so the literal path isn't captured as an id.
                Route::get('/teacher/content-packages', [ContentPackageController::class, 'index']);
                Route::post('/teacher/content-packages', [ContentPackageController::class, 'store']);
                Route::get('/teacher/content-packages/{package}', [ContentPackageController::class, 'show']);
                Route::put('/teacher/content-packages/{package}', [ContentPackageController::class, 'update']);
                Route::delete('/teacher/content-packages/{package}', [ContentPackageController::class, 'destroy']);

                Route::post('/teacher/content-packages/{package}/items', [ContentPackageController::class, 'storeItem']);
                Route::put('/teacher/content-packages/{package}/items/reorder', [ContentPackageController::class, 'reorderItems']);
                Route::delete('/teacher/content-packages/{package}/items/{item}', [ContentPackageController::class, 'destroyItem']);

                // Package types (B27) — teacher-managed content-package categories,
                // scoped to the active year. Bind by uuid within that year (a type
                // from another year/tenant 404s). Packages link one via
                // package_type_id on create/update.
                Route::get('/teacher/package-types', [PackageTypeController::class, 'index']);
                Route::post('/teacher/package-types', [PackageTypeController::class, 'store']);
                Route::get('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'show']);
                Route::put('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'update']);
                Route::delete('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'destroy']);
            });

            Route::get('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'index']);
            Route::post('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store']);
            Route::delete('/teacher/lessons/{lesson}/attachments/{attachment:uuid}', [LessonAttachmentController::class, 'destroy']);

            // Lesson time-box config (availability window + extension allowance).
            Route::get('/teacher/lessons/{lesson}/availability', [LessonAvailabilityController::class, 'show']);
            Route::put('/teacher/lessons/{lesson}/availability', [LessonAvailabilityController::class, 'update']);
            // Open a lesson for one student for a custom number of hours (doc 11 R4).
            Route::post('/teacher/lessons/{lesson}/reopen', [LessonAvailabilityController::class, 'reopen']);

            // Student extension requests — staff review + grant/deny.
            Route::get('/teacher/extension-requests', [ExtensionRequestController::class, 'index']);
            Route::post('/teacher/extension-requests/{extensionRequest}/grant', [ExtensionRequestController::class, 'grant']);
            Route::post('/teacher/extension-requests/{extensionRequest}/deny', [ExtensionRequestController::class, 'deny']);

            // Coupons & promo codes (M21) — teacher-managed discounts at checkout.
            Route::get('/teacher/coupons', [CouponController::class, 'index']);
            Route::post('/teacher/coupons', [CouponController::class, 'store']);
            Route::get('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'show']);
            Route::put('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'update']);
            Route::delete('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'destroy']);

            // Q&A forum + moderation (M09) — aggregate of lesson questions across
            // the academy's courses. Teachers reply via /comments/{comment}/replies.
            Route::get('/teacher/forum', [ForumController::class, 'index']);
            Route::patch('/teacher/comments/{comment}', [ForumController::class, 'update']);
            Route::delete('/teacher/comments/{comment}', [ForumController::class, 'destroy']);

            // Self-hosted video (M04) — upload → transcode → status.
            Route::post('/teacher/media/uploads', [TeacherMediaController::class, 'startUpload']);
            Route::post('/teacher/media/uploads/{media:uuid}/complete', [TeacherMediaController::class, 'completeUpload']);
            Route::get('/teacher/media/{media:uuid}', [TeacherMediaController::class, 'show']);
            // Teacher self-preview → same encrypted-HLS flow (returns manifest_url + key_url).
            Route::post('/teacher/media/{media:uuid}/preview', [TeacherMediaController::class, 'preview']);

            // Remote (OVH Media Host) video lifecycle — active when MEDIA_PROVIDER=remote.
            // Bound models are tenant-scoped, so cross-tenant ids resolve to 404.
            Route::post('/teacher/remote-videos/uploads', [RemoteVideoController::class, 'startUpload']);
            Route::post('/teacher/remote-videos/uploads/{session}/complete', [RemoteVideoController::class, 'complete']);
            Route::get('/teacher/remote-videos/{media:uuid}', [RemoteVideoController::class, 'show']);
            Route::post('/teacher/remote-videos/{media:uuid}/replace', [RemoteVideoController::class, 'replace']);
            Route::post('/teacher/remote-videos/versions/{version}/retry', [RemoteVideoController::class, 'retry']);
            Route::post('/teacher/remote-videos/versions/{version}/quarantine', [RemoteVideoController::class, 'quarantine']);
            Route::post('/teacher/remote-videos/versions/{version}/restore', [RemoteVideoController::class, 'restore']);
            Route::delete('/teacher/remote-videos/versions/{version}', [RemoteVideoController::class, 'purge']);

            // Exams — teacher authoring + grading (M08). Managed from the sidebar
            // (top-level, NOT course-nested). `type` drives the link + auto-fill;
            // filter the index by ?type=&course_id=&unit_id=&lesson_id=.
            Route::get('/teacher/exams', [ExamController::class, 'index']);
            Route::post('/teacher/exams', [ExamController::class, 'store']);
            // Link-target dropdown for the exam editor (lesson picker).
            Route::get('/teacher/exam-link/lessons', [ExamLinkController::class, 'lessons']);
            Route::get('/teacher/exams/{exam:uuid}', [ExamController::class, 'show']);
            Route::put('/teacher/exams/{exam:uuid}', [ExamController::class, 'update']);
            Route::delete('/teacher/exams/{exam:uuid}', [ExamController::class, 'destroy']);

            Route::get('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'index']);
            Route::post('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'store']);
            Route::put('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'update']);
            Route::delete('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'destroy']);

            // On-site bubble-sheet MCQ builder (doc 13 Phase 7) — read/replace the
            // whole answer sheet at once. Year-scoped (X-Academic-Year) like the rest
            // of lesson/part authoring; the answer key is teacher-only.
            Route::middleware('academic-year')->group(function (): void {
                Route::get('/teacher/exams/{exam:uuid}/bubble-sheet', [BubbleSheetController::class, 'show']);
                Route::put('/teacher/exams/{exam:uuid}/bubble-sheet', [BubbleSheetController::class, 'update']);
            });

            // Exam/quiz time-extension requests — staff review (doc 11 R6).
            Route::get('/teacher/exam-extension-requests', [ExamExtensionRequestController::class, 'index']);
            Route::post('/teacher/exam-extension-requests/{examExtension}/grant', [ExamExtensionRequestController::class, 'grant']);
            Route::post('/teacher/exam-extension-requests/{examExtension}/deny', [ExamExtensionRequestController::class, 'deny']);

            // Gamification (M19) — badges + ranking toggle
            Route::get('/teacher/badges', [BadgeController::class, 'index']);
            Route::post('/teacher/badges', [BadgeController::class, 'store']);
            Route::delete('/teacher/badges/{badge}', [BadgeController::class, 'destroy']);
            Route::get('/teacher/gamification', [BadgeController::class, 'settings']);
            Route::put('/teacher/gamification', [BadgeController::class, 'updateSettings']);

            // Teacher reports (M17, basic)
            Route::get('/teacher/reports/sales', [TeacherReportsController::class, 'sales']);
            Route::get('/teacher/reports/students', [TeacherReportsController::class, 'students']);
            Route::get('/teacher/reports/overview', [TeacherReportsController::class, 'overview']);

            // Audit log (M18)
            Route::get('/teacher/audit-logs', [AuditLogController::class, 'teacher']);

            // Assistants + granular permissions (M18) — teacher-only management.
            Route::get('/teacher/permissions', [AssistantController::class, 'catalog']);
            Route::get('/teacher/assistants', [AssistantController::class, 'index']);
            Route::post('/teacher/assistants', [AssistantController::class, 'store']);
            Route::get('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'show']);
            Route::patch('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'update']);
            Route::delete('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'destroy']);

            // Notification engine (doc 10 §9.2) — tenant override surface for
            // `ready` system notifications. First edit materializes a copy-on-write
            // tenant template; teachers can't author types/templates from scratch.
            Route::get('/teacher/notifications', [TeacherNotificationController::class, 'index']);
            Route::get('/teacher/notifications/{type:key}', [TeacherNotificationController::class, 'show']);
            Route::put('/teacher/notifications/{type:key}/channels', [TeacherNotificationController::class, 'overrideChannel']);
            Route::put('/teacher/notifications/{type:key}/channels/{channel}/translations', [TeacherNotificationController::class, 'upsertTranslation']);
            Route::delete('/teacher/notifications/{type:key}/channels/{channel}', [TeacherNotificationController::class, 'reset']);
        });

        // Shared teacher + assistant surface (M18): an assistant reaches these
        // only for the permissions the teacher granted; a teacher passes every
        // permission check implicitly.
        Route::middleware('role:teacher,assistant')->group(function (): void {

            // Centers (M12) — branches, activation codes, attendance, offline sync
            Route::middleware('permission:centers')->group(function (): void {
                Route::get('/teacher/centers', [CenterController::class, 'index']);
                Route::post('/teacher/centers', [CenterController::class, 'store']);
                Route::put('/teacher/centers/{center:uuid}', [CenterController::class, 'update']);
                Route::delete('/teacher/centers/{center:uuid}', [CenterController::class, 'destroy']);
                Route::post('/teacher/centers/sync', CenterSyncController::class);
                Route::get('/teacher/centers/{center:uuid}/attendance', [AttendanceController::class, 'index']);
                Route::post('/teacher/centers/{center:uuid}/attendance', [AttendanceController::class, 'store']);
                Route::get('/teacher/codes', [ActivationCodeController::class, 'index']);
                Route::post('/teacher/codes/batch', [ActivationCodeController::class, 'batch']);
                Route::post('/teacher/codes/{code:uuid}/disable', [ActivationCodeController::class, 'disable']);

                // Center ID-codes (B20) — sequential, grade-encoded student-identity
                // codes minted per center; a sibling of /codes, NOT the recharge codes.
                // Year-scoped (X-Academic-Year): the panel's year selector filters
                // the list, and a batch is stamped with the active academic year.
                Route::middleware('academic-year')->group(function (): void {
                    Route::get('/teacher/center-id-codes', [CenterIdCodeController::class, 'index']);
                    Route::post('/teacher/center-id-codes/batch', [CenterIdCodeController::class, 'batch']);
                });

                // Center paper-exam grade entry (VD R12, doc 13 Phase 15). A grade
                // belongs to an academic year, so these are year-scoped
                // (X-Academic-Year); {grade} binds by uuid within the active year.
                Route::middleware('academic-year')->group(function (): void {
                    Route::get('/teacher/center-exam-grades', [CenterExamGradeController::class, 'index']);
                    Route::post('/teacher/center-exam-grades', [CenterExamGradeController::class, 'store']);
                    Route::put('/teacher/center-exam-grades/{grade:uuid}', [CenterExamGradeController::class, 'update']);
                    Route::delete('/teacher/center-exam-grades/{grade:uuid}', [CenterExamGradeController::class, 'destroy']);
                });
            }); // permission:centers

            // Homework grading (doc 11 R3.4) — teacher, or an assistant granted the
            // `homework` permission, reviews/corrects student assignment submissions.
            Route::middleware('permission:homework')->group(function (): void {
                Route::get('/teacher/exams/{exam:uuid}/submissions', [ExamGradingController::class, 'submissions']);
                Route::get('/teacher/exams/{exam:uuid}/attempts/{attempt}/files/{question}', [ExamGradingController::class, 'downloadFile']);
                Route::post('/teacher/exams/{exam:uuid}/attempts/{attempt}/grade', [ExamGradingController::class, 'grade']);

                // Manual pass-override on a must_pass part (VD change set §7 LP-D3).
                // Year-scoped like the rest of lesson authoring.
                Route::middleware('academic-year')->group(function (): void {
                    Route::post('/teacher/lessons/{lesson}/sections/{section}/pass-override', [LessonSectionController::class, 'storePassOverride']);
                    // {user} is resolved independently of {section} — the controller
                    // scopes the delete by (section, user). Without this, Laravel
                    // auto-scopes the child and tries LessonSection::users() → 500.
                    Route::delete('/teacher/lessons/{lesson}/sections/{section}/pass-override/{user:uuid}', [LessonSectionController::class, 'destroyPassOverride'])
                        ->withoutScopedBindings();
                });
            }); // permission:homework

            // Students (M17) — teacher, or an assistant granted the `students` permission.
            Route::middleware('permission:students')->group(function (): void {
                Route::get('/teacher/students', [StudentController::class, 'index']);
                Route::post('/teacher/students', [StudentController::class, 'store']);
                // Bulk student-history import (.xlsx/.csv) — matched by phone/email.
                Route::post('/teacher/students/import', StudentImportController::class);
                Route::get('/teacher/students/{student:uuid}', [StudentController::class, 'show']);
                Route::patch('/teacher/students/{student:uuid}', [StudentController::class, 'update']);
                Route::delete('/teacher/students/{student:uuid}', [StudentController::class, 'destroy']);
                Route::post('/teacher/students/{student:uuid}/reset-password', [StudentController::class, 'resetPassword']);
                Route::get('/teacher/students/{student:uuid}/export', [StudentController::class, 'export']);

                // Manual content-access overrides — grant/revoke a student direct
                // access to a locked lesson/section/unit, bypassing dependencies.
                Route::get('/teacher/students/{student:uuid}/content-overrides', [StudentContentOverrideController::class, 'index']);
                Route::post('/teacher/students/{student:uuid}/content-overrides', [StudentContentOverrideController::class, 'store']);
                Route::delete('/teacher/students/{student:uuid}/content-overrides/{override}', [StudentContentOverrideController::class, 'destroy']);

                // Access (enrollments)
                Route::get('/teacher/students/{student:uuid}/enrollments', [StudentEnrollmentController::class, 'index']);
                Route::post('/teacher/students/{student:uuid}/enrollments', [StudentEnrollmentController::class, 'store']);
                Route::delete('/teacher/students/{student:uuid}/enrollments/{enrollment}', [StudentEnrollmentController::class, 'destroy']);

                // Money
                Route::get('/teacher/students/{student:uuid}/wallet', [StudentFinanceController::class, 'wallet']);
                Route::get('/teacher/students/{student:uuid}/wallet/ledger', [StudentFinanceController::class, 'ledger']);
                Route::post('/teacher/students/{student:uuid}/wallet/adjust', [StudentFinanceController::class, 'adjust']);
                Route::post('/teacher/students/{student:uuid}/wallet/set', [StudentFinanceController::class, 'setBalance']);
                Route::get('/teacher/students/{student:uuid}/orders', [StudentFinanceController::class, 'orders']);

                // Activity
                Route::get('/teacher/students/{student:uuid}/progress', [StudentActivityController::class, 'progress']);
                Route::get('/teacher/students/{student:uuid}/activity', [StudentActivityController::class, 'history']);
                Route::post('/teacher/students/{student:uuid}/notify', [StudentActivityController::class, 'notify']);

                // Parents (M13)
                Route::get('/teacher/students/{student:uuid}/parents', [StudentParentController::class, 'index']);
                Route::post('/teacher/students/{student:uuid}/parents', [StudentParentController::class, 'store']);
                // `parent` is resolved independently of `student` — the controller already
                // scopes the ParentLink delete by (student, parent). Without this, Laravel
                // auto-enables scoped binding for the custom-key child and tries to resolve
                // it via a nonexistent User::parents() relationship → 500. (Bug fix.)
                Route::delete('/teacher/students/{student:uuid}/parents/{parent:uuid}', [StudentParentController::class, 'destroy'])
                    ->withoutScopedBindings();
                // Re-issue a linked parent's password. `parent` resolved independently
                // of `student` (same reason as destroy above); controller scopes by link.
                Route::post('/teacher/students/{student:uuid}/parents/{parent:uuid}/reset-password', [StudentParentController::class, 'resetPassword'])
                    ->withoutScopedBindings();
                // Passwordless magic link (VD R11): issue (rotates) / revoke. `parent`
                // resolved independently of `student` (same reason as reset-password).
                Route::post('/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link', [StudentParentController::class, 'magicLink'])
                    ->withoutScopedBindings();
                Route::delete('/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link', [StudentParentController::class, 'revokeMagicLink'])
                    ->withoutScopedBindings();
            }); // permission:students

            // Manual payment-receipt verification (VD R9/R10) — teacher, or an
            // assistant granted `finance`, reviews manual wallet top-ups. Tenant-level,
            // NOT year-scoped (no X-Academic-Year).
            Route::middleware('permission:finance')->group(function (): void {
                Route::get('/teacher/payment-receipts', [PaymentReceiptController::class, 'index']);
                Route::get('/teacher/payment-receipts/{receipt:uuid}', [PaymentReceiptController::class, 'show']);
                Route::post('/teacher/payment-receipts/{receipt:uuid}/approve', [PaymentReceiptController::class, 'approve']);
                Route::post('/teacher/payment-receipts/{receipt:uuid}/reject', [PaymentReceiptController::class, 'reject']);
            }); // permission:finance

            // Support tickets — staff side (M09, B25 / VD Item 11). Teacher, or an
            // assistant granted `support`, lists every ticket (filter ?status=
            // &priority=), reads a thread, replies (notifies the student), and
            // moves the status. {ticket} binds by uuid, tenant-scoped (no owner
            // check — staff see the whole tenant). Student side: /support/tickets.
            Route::middleware('permission:support')->group(function (): void {
                Route::get('/teacher/support/tickets', [TeacherSupportTicketController::class, 'index']);
                Route::get('/teacher/support/tickets/{ticket}', [TeacherSupportTicketController::class, 'show']);
                Route::post('/teacher/support/tickets/{ticket}/replies', [TeacherSupportTicketController::class, 'reply']);
                Route::patch('/teacher/support/tickets/{ticket}/status', [TeacherSupportTicketController::class, 'updateStatus']);
            }); // permission:support
        }); // role:teacher,assistant
    });
});
