<?php

use App\Modules\Assessment\Http\Controllers\AttemptController;
use App\Modules\Assessment\Http\Controllers\Teacher\BubbleSheetController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamExtensionRequestController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamGradingController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamLinkController;
use App\Modules\Assessment\Http\Controllers\Teacher\QuestionController;
use App\Modules\Billing\Http\Controllers\Admin\PackageController;
use App\Modules\Billing\Http\Controllers\Admin\TenantBillingController;
use App\Modules\Billing\Http\Controllers\Admin\TenantSubscriptionController;
use App\Modules\Billing\Http\Controllers\Teacher\PackageController as TeacherPackageController;
use App\Modules\Billing\Http\Controllers\Teacher\SubscriptionController;
use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Catalog\Http\Controllers\StudentLessonAccessController;
use App\Modules\Catalog\Http\Controllers\StudentLessonSectionsController;
use App\Modules\Catalog\Http\Controllers\StudentLibraryController;
use App\Modules\Catalog\Http\Controllers\Teacher\AcademicYearController;
use App\Modules\Catalog\Http\Controllers\Teacher\ContentPackageController;
use App\Modules\Catalog\Http\Controllers\Teacher\ExtensionRequestController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonAttachmentController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonAvailabilityController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonSectionController;
use App\Modules\Catalog\Http\Controllers\Teacher\PackageTypeController;
use App\Modules\Centers\Http\Controllers\PublicCenterController;
use App\Modules\Centers\Http\Controllers\RedeemCodeController;
use App\Modules\Centers\Http\Controllers\StudentCenterExamGradeController;
use App\Modules\Centers\Http\Controllers\Teacher\ActivationCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\AttendanceController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterExamGradeController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterIdCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterSessionController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterSyncController;
use App\Modules\Centers\Http\Controllers\Teacher\SessionAttendanceController;
use App\Modules\Commerce\Http\Controllers\CheckoutController;
use App\Modules\Commerce\Http\Controllers\InvoiceController;
use App\Modules\Commerce\Http\Controllers\PaymentWebhookController;
use App\Modules\Commerce\Http\Controllers\Teacher\CouponController;
use App\Modules\Commerce\Http\Controllers\Teacher\RefundController;
use App\Modules\Engagement\Http\Controllers\AttachmentController;
use App\Modules\Engagement\Http\Controllers\CommentController;
use App\Modules\Engagement\Http\Controllers\FavoriteController;
use App\Modules\Engagement\Http\Controllers\GamificationController;
use App\Modules\Engagement\Http\Controllers\ProgressController;
use App\Modules\Engagement\Http\Controllers\ReviewController;
use App\Modules\Engagement\Http\Controllers\SupportTicketController;
use App\Modules\Engagement\Http\Controllers\Teacher\BadgeController;
use App\Modules\Engagement\Http\Controllers\Teacher\ForumController;
use App\Modules\Engagement\Http\Controllers\Teacher\ReviewController as TeacherReviewController;
use App\Modules\Engagement\Http\Controllers\Teacher\SupportTicketController as TeacherSupportTicketController;
use App\Modules\Identity\Http\Controllers\Admin\RoleTemplateController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\ParentController;
use App\Modules\Identity\Http\Controllers\Teacher\AssistantController;
use App\Modules\Identity\Http\Controllers\Teacher\RoleController;
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
use App\Modules\Notifications\Http\Controllers\Admin\BroadcastController as AdminBroadcastController;
use App\Modules\Notifications\Http\Controllers\Admin\EventController as AdminNotificationEventController;
use App\Modules\Notifications\Http\Controllers\Admin\TemplateController as AdminNotificationTemplateController;
use App\Modules\Notifications\Http\Controllers\Admin\TranslationController as AdminNotificationTranslationController;
use App\Modules\Notifications\Http\Controllers\Admin\TypeController as AdminNotificationTypeController;
use App\Modules\Notifications\Http\Controllers\InboxController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\Teacher\BroadcastController as TeacherBroadcastController;
use App\Modules\Notifications\Http\Controllers\Teacher\SmsSettingsController;
use App\Modules\Notifications\Http\Controllers\Teacher\TeacherNotificationController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminReportController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminTenantController;
use App\Modules\PlatformAdmin\Http\Controllers\ImpersonationController;
use App\Modules\Reporting\Http\Controllers\AuditLogController;
use App\Modules\Reporting\Http\Controllers\StudentCoursesController;
use App\Modules\Reporting\Http\Controllers\Teacher\SalesLedgerController;
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
    Route::post('/webhooks/fawry', [PaymentWebhookController::class, 'fawry'])->middleware('throttle:120,1');

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
    // Soft-remove an academy (AdminTenantController::destroy — reversible, not a purge).
    Route::delete('/admin/tenants/{tenant:uuid}', [AdminTenantController::class, 'destroy']);
    // Role templates (M20) — the blueprints tenants are stamped from. Editing one
    // affects only academies created afterwards; `resync` is the explicit, and
    // destructive, way to push a change onto the copies that already exist.
    Route::get('/admin/role-templates', [RoleTemplateController::class, 'index']);
    Route::get('/admin/role-templates/permissions', [RoleTemplateController::class, 'permissions']);
    Route::put('/admin/role-templates/{roleTemplate}', [RoleTemplateController::class, 'update']);
    Route::get('/admin/role-templates/{roleTemplate}/resync-preview', [RoleTemplateController::class, 'resyncPreview']);
    Route::post('/admin/role-templates/{roleTemplate}/resync', [RoleTemplateController::class, 'resync']);

    Route::get('/admin/reports/overview', [AdminReportController::class, 'overview']);
    Route::get('/admin/reports/platform-business', [AdminReportController::class, 'platformBusiness']);
    Route::get('/admin/audit-logs', [AuditLogController::class, 'admin']);
    Route::get('/admin/audit-logs/actions', [AuditLogController::class, 'actions']);
    Route::get('/admin/audit-logs/export', [AuditLogController::class, 'export']);

    // Teacher subscription packages (M03) — define plans + assign them to tenants.
    Route::get('/admin/packages', [PackageController::class, 'index']);
    Route::post('/admin/packages', [PackageController::class, 'store']);
    Route::get('/admin/packages/{package:uuid}', [PackageController::class, 'show']);
    Route::put('/admin/packages/{package:uuid}', [PackageController::class, 'update']);
    Route::delete('/admin/packages/{package:uuid}', [PackageController::class, 'destroy']);

    Route::get('/admin/tenants/{tenant:uuid}/subscription', [TenantSubscriptionController::class, 'show']);
    Route::post('/admin/tenants/{tenant:uuid}/subscription', [TenantSubscriptionController::class, 'store']);

    // Supervised, read-only impersonation of the academy owner (ADM-17). The
    // exit route sits outside this group: it is called WITH the impersonation
    // token, which is not a platform-admin one.
    Route::post('/admin/tenants/{tenant:uuid}/impersonate', [ImpersonationController::class, 'start']);
    // What the academy has actually paid us, and the ability to record it.
    Route::get('/admin/tenants/{tenant:uuid}/billing', [TenantBillingController::class, 'index']);
    Route::post('/admin/tenants/{tenant:uuid}/billing', [TenantBillingController::class, 'store']);

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
    Route::get('/admin/notifications/events/{event}/failures', [AdminNotificationEventController::class, 'failures']);

    // Custom notifications, platform scope: admin → every teacher, in-app and
    // email. `preview` answers the reach before `store` commits to it.
    Route::get('/admin/notifications/custom', [AdminBroadcastController::class, 'index']);
    Route::post('/admin/notifications/custom/preview', [AdminBroadcastController::class, 'preview']);
    Route::post('/admin/notifications/custom', [AdminBroadcastController::class, 'store']);
    Route::get('/admin/notifications/custom/{broadcast}', [AdminBroadcastController::class, 'show']);
});

/*
| Ending an impersonation session. Authenticated with the IMPERSONATION token
| (which belongs to the academy owner, not to the admin), so it cannot live in
| the platform-admin group above — and it must always be reachable, or an admin
| could not get out of a supervised session.
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
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

    // Public catalogue (M04) — published courses of the resolved tenant. Year-aware
    // via `academic-year:optional`: when the SPA sends X-Academic-Year the listing
    // scopes to that year (courses/packages/types/reviews all carry academic_year_id
    // now); with no header it stays tenant-wide, so anonymous browse never 422s.
    Route::middleware('academic-year:optional')->group(function (): void {
        // Public catalogue (VD §7 — `courses` retired): default lists packages,
        // ?view=lessons lists standalone lessons.
        Route::get('/catalogue', [PublicCatalogController::class, 'index']);
        // Content-package types (B27) — for the student-facing package filter.
        Route::get('/package-types', [PublicCatalogController::class, 'packageTypes']);
        // Public package detail (name + ordered items) for the package-detail page.
        Route::get('/packages/{package:uuid}', [PublicCatalogController::class, 'showPackage']);
        // Public reviews for a content target (?target_type=lesson|package&target_id=).
        Route::get('/reviews', [ReviewController::class, 'index']);
    });

    // Public academic-year (grade) list for the registration grade picker. Tenant-
    // scoped; NOT year-scoped (this is where a student picks their year).
    Route::get('/academic-years', [PublicCatalogController::class, 'academicYears']);

    // Public center (branch) list for the registration center picker — a center
    // student must pick the branch they attend, and `center` is validated as a
    // uuid, which the student can only supply from this list.
    Route::get('/centers', PublicCenterController::class)->middleware('throttle:public');

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
    // `academic-year:optional` runs site-wide here: it resolves the X-Academic-Year
    // header into AcademicYearContext when the client sends one (so every content
    // query — teacher, student, parent — scopes to that year), and no-ops when it
    // doesn't (tenant-only, nothing breaks). Authoring surfaces that MUST stamp a
    // year keep their own nested strict `academic-year` group, which still 422s on
    // a missing header. Mounted here, not on the outer `tenant` group, so the
    // public /auth/* routes never see it.
    Route::middleware(['auth:sanctum', 'active', 'academic-year:optional'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', MeController::class);

        // Content reviews — a student with access rates a lesson/package (upsert)
        Route::post('/reviews', [ReviewController::class, 'store']);

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

        // Favorites (M20) — target a lesson|package (VD §7)
        Route::get('/me/favorites', [FavoriteController::class, 'index']);
        Route::post('/me/favorites', [FavoriteController::class, 'store']);
        Route::delete('/me/favorites/{type}/{id}', [FavoriteController::class, 'destroy'])
            ->where('type', 'lesson|package')->where('id', '[0-9]+');

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
            // The academy's public-facing teacher profile (bio, photo, socials) —
            // site content, so it rides with the landing page key.
            Route::middleware('can:settings.landing.manage')->group(function (): void {
                Route::get('/teacher/profile', [TeacherProfileController::class, 'show']);
                Route::put('/teacher/profile', [TeacherProfileController::class, 'update']);
            });

            // Access switches (M02) — open/close sign-in + self-registration for
            // this academy. Enforced at /auth/login + /auth/register (M11).
            // Closing sign-in locks every member out of the academy, so it is its
            // own key rather than a corner of the landing settings.
            Route::middleware('can:settings.access.manage')->group(function (): void {
                Route::get('/teacher/access', [TenantAccessController::class, 'show']);
                Route::put('/teacher/access', [TenantAccessController::class, 'update']);
            });

            // Custom-landing switch (M02) — ON = SPA renders its own bundled
            // custom/<slug>/ page; OFF (default) = the CMS landing sections.
            // Mirrored in GET /tenant/context → data.landing.custom_enabled.
            Route::middleware('can:settings.landing.manage')->group(function (): void {
                Route::get('/teacher/custom-landing', [TeacherCustomLandingController::class, 'show']);
                Route::put('/teacher/custom-landing', [TeacherCustomLandingController::class, 'update']);
            });

            // SMS settings (M10) — teacher stores his own WE Business SMS
            // (Connekio) credentials; SMS only works for the tenant once set +
            // enabled. Password is write-only; stored encrypted per tenant.
            Route::middleware('can:settings.sms.manage')->group(function (): void {
                Route::get('/teacher/sms-settings', [SmsSettingsController::class, 'show']);
                Route::put('/teacher/sms-settings', [SmsSettingsController::class, 'update']);
            });

            // Custom domains (M02) — attach the academy's own domain. The host
            // resolves to this tenant once the DNS record is set; the auto
            // subdomain stays read-only. {domain} is a uuid, scoped in-controller.
            Route::middleware('can:settings.domains.manage')->group(function (): void {
                Route::get('/teacher/domains', [DomainController::class, 'index']);
                Route::post('/teacher/domains', [DomainController::class, 'store']);
                Route::post('/teacher/domains/{domain}/primary', [DomainController::class, 'setPrimary']);
                Route::delete('/teacher/domains/{domain}', [DomainController::class, 'destroy']);
            });

            // Teacher subscription (M03) — read-only view of the tenant's plan,
            // limits, and usage. The plan is managed by the platform admin.

            // Available plans to compare (each flagged is_current). Read-only —
            // switching is admin-driven (see docs/api/billing.md).

            Route::middleware('can:settings.landing.manage')->group(function (): void {
                Route::get('/teacher/landing', [TeacherLandingController::class, 'show']);
                Route::put('/teacher/landing', [TeacherLandingController::class, 'update']);
                Route::post('/teacher/landing/media', [TeacherLandingController::class, 'media']);
            });

            // Site metadata (M02) — teacher-managed key/value entries (SEO tags,
            // custom head data, …), namespaced by `group`. Separate from the
            // landing/profile config; bound by id and tenant-scoped.
            Route::middleware('can:settings.meta.manage')->group(function (): void {
                Route::get('/teacher/meta', [TeacherMetaController::class, 'index']);
                Route::post('/teacher/meta', [TeacherMetaController::class, 'store']);
                Route::get('/teacher/meta/{meta}', [TeacherMetaController::class, 'show']);
                Route::put('/teacher/meta/{meta}', [TeacherMetaController::class, 'update']);
                Route::delete('/teacher/meta/{meta}', [TeacherMetaController::class, 'destroy']);
            });

            // Reviews & landing testimonials (M20) — teacher-panel CRUD: moderate
            // student reviews (hide/show/edit/delete) + author curated testimonials.
            Route::middleware('can:community.reviews.manage')->group(function (): void {
                Route::get('/teacher/reviews', [TeacherReviewController::class, 'index']);
                Route::post('/teacher/reviews', [TeacherReviewController::class, 'store']);
                Route::get('/teacher/reviews/{review}', [TeacherReviewController::class, 'show']);
                Route::put('/teacher/reviews/{review}', [TeacherReviewController::class, 'update']);
                Route::delete('/teacher/reviews/{review}', [TeacherReviewController::class, 'destroy']);
            });

            // Q&A forum + moderation (M09) — aggregate of lesson questions across
            // the academy's courses. Teachers reply via /comments/{comment}/replies.
            // Reading the question feed and moderating it are separate powers.
            Route::get('/teacher/forum', [ForumController::class, 'index'])->middleware('can:community.forum.view');
            Route::middleware('can:community.forum.moderate')->group(function (): void {
                Route::patch('/teacher/comments/{comment}', [ForumController::class, 'update']);
                Route::delete('/teacher/comments/{comment}', [ForumController::class, 'destroy']);
            });

            // Gamification (M19) — badges + ranking toggle
            Route::middleware('can:community.badges.manage')->group(function (): void {
                Route::get('/teacher/badges', [BadgeController::class, 'index']);
                Route::post('/teacher/badges', [BadgeController::class, 'store']);
                Route::delete('/teacher/badges/{badge}', [BadgeController::class, 'destroy']);
            });

            // The ranking/points switches affect what every student sees.
            Route::middleware('can:community.gamification.manage')->group(function (): void {
                Route::get('/teacher/gamification', [BadgeController::class, 'settings']);
                Route::put('/teacher/gamification', [BadgeController::class, 'updateSettings']);
            });

            // Teacher reports (M17, basic)

            // Audit log (M18)

            // Roles & permissions (M20) — the academy's own copies of the platform
            // templates, plus whatever the teacher created. Read side for now;
            // authoring moves in behind `can:team.roles.manage`.

            // Assistants + granular permissions (M18) — teacher-only management.

            // Notification engine (doc 10 §9.2) — tenant override surface for
            // `ready` system notifications. First edit materializes a copy-on-write
            // tenant template; teachers can't author types/templates from scratch.
            Route::middleware('can:settings.notifications.manage')->group(function (): void {
                Route::get('/teacher/notifications', [TeacherNotificationController::class, 'index']);
                Route::get('/teacher/notifications/{type:key}', [TeacherNotificationController::class, 'show']);
                Route::put('/teacher/notifications/{type:key}/channels', [TeacherNotificationController::class, 'overrideChannel']);
                Route::put('/teacher/notifications/{type:key}/channels/{channel}/translations', [TeacherNotificationController::class, 'upsertTranslation']);
                Route::delete('/teacher/notifications/{type:key}/channels/{channel}', [TeacherNotificationController::class, 'reset']);
            });
        });

        // Shared teacher + assistant surface (M18): an assistant reaches these
        // only for the permissions the teacher granted; a teacher passes every
        // permission check implicitly.
        // Authority on this surface comes from PERMISSIONS, not from the kind of
        // membership (M20). There is deliberately no `role:teacher,assistant` gate
        // here: a student holding a granted key would otherwise be refused by a
        // check that has nothing to do with what they may do — which is exactly
        // the implicit authority this milestone removed. Every route below carries
        // its own `can:`, and a member with no keys reaches none of them.
        Route::group([], function (): void {

            // ── Custom notifications ─────────────────────────────────────
            // A human-written message to a slice of the academy: everyone, one
            // lesson, one package, one grade, one center, hand-picked students,
            // or the assistants. Own permission (`settings.notifications.send`),
            // which an assistant holds only if the teacher granted it — sending
            // spends the academy's SMS credit and reaches muted students.
            //
            // Path is `custom-notifications`, NOT `notifications/custom`: the
            // override surface above already owns `/teacher/notifications/{type:key}`
            // and would swallow a `custom` segment.
            Route::middleware('can:settings.notifications.send')->group(function (): void {
                Route::get('/teacher/custom-notifications', [TeacherBroadcastController::class, 'index']);
                // Static segments first — otherwise {broadcast} eats them.
                Route::get('/teacher/custom-notifications/channels', [TeacherBroadcastController::class, 'channels']);
                Route::post('/teacher/custom-notifications/preview', [TeacherBroadcastController::class, 'preview']);
                Route::post('/teacher/custom-notifications', [TeacherBroadcastController::class, 'store']);
                Route::get('/teacher/custom-notifications/{broadcast}', [TeacherBroadcastController::class, 'show']);
                Route::post('/teacher/custom-notifications/{broadcast}/cancel', [TeacherBroadcastController::class, 'cancel']);
            });

            // ── Team (M20) ───────────────────────────────────────────────
            // The owner may delegate team management, but the delegation cannot
            // propagate: TeamAuthority refuses to let anyone but the owner grant a
            // `team.*` key, or grant a permission they do not hold themselves. So
            // these routes are reachable by a delegate, and still not a ladder.
            Route::middleware('can:team.view')->group(function (): void {
                Route::get('/teacher/roles', [RoleController::class, 'index']);
                Route::get('/teacher/permissions', [RoleController::class, 'permissions']);
                Route::get('/teacher/assistants', [AssistantController::class, 'index']);
                Route::get('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'show']);
            });

            // Authoring roles. System roles are refused by the writer, not just by
            // the controller, so the lock holds for every caller.
            Route::middleware('can:team.roles.manage')->group(function (): void {
                Route::post('/teacher/roles', [RoleController::class, 'store']);
                Route::put('/teacher/roles/{uuid}', [RoleController::class, 'update']);
                Route::delete('/teacher/roles/{uuid}', [RoleController::class, 'destroy']);
            });

            Route::middleware('can:team.assistants.manage')->group(function (): void {
                Route::post('/teacher/assistants', [AssistantController::class, 'store']);
                Route::patch('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'update']);
                Route::delete('/teacher/assistants/{assistant:uuid}', [AssistantController::class, 'destroy']);
            });

            // ── Content (M20) ────────────────────────────────────────────
            // Gated per ACTION with `can:`, not per screen: an assistant may be
            // able to edit a lesson without being able to delete one. The owner
            // passes because the owner role carries every key, not by exception.
            //
            // `content.view` is the read floor for the domain; each write carries
            // its own key on top of it.

            // Academic years — WRITE side. Bind by uuid; NOT behind the
            // `academic-year` middleware (this is where years are managed).
            Route::middleware('can:content.academic_years.manage')->group(function (): void {
                Route::post('/teacher/academic-years', [AcademicYearController::class, 'store']);
                Route::put('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'update']);
                Route::delete('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'destroy']);
            });

            // Standalone lessons + their parts. Year-scoped: every request carries
            // X-Academic-Year; {lesson}/{section} bind by id within the active
            // year, so a lesson from another year (or tenant) 404s.
            Route::middleware('academic-year')->group(function (): void {
                Route::middleware('can:content.view')->group(function (): void {
                    Route::get('/teacher/lessons', [LessonController::class, 'index']);
                    Route::get('/teacher/lessons/{lesson}', [LessonController::class, 'show']);
                    Route::get('/teacher/lessons/{lesson}/sections', [LessonSectionController::class, 'index']);
                    Route::get('/teacher/content-packages', [ContentPackageController::class, 'index']);
                    Route::get('/teacher/content-packages/{package}', [ContentPackageController::class, 'show']);
                    Route::get('/teacher/package-types', [PackageTypeController::class, 'index']);
                    Route::get('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'show']);
                });

                Route::post('/teacher/lessons', [LessonController::class, 'store'])->middleware('can:content.lessons.create');
                Route::put('/teacher/lessons/{lesson}', [LessonController::class, 'update'])->middleware('can:content.lessons.update');
                Route::delete('/teacher/lessons/{lesson}', [LessonController::class, 'destroy'])->middleware('can:content.lessons.delete');

                // Parts (reuse lesson_sections). `reorder` is registered before the
                // `{section}` route so the literal path isn't captured as an id.
                Route::middleware('can:content.lesson_sections.manage')->group(function (): void {
                    Route::post('/teacher/lessons/{lesson}/sections', [LessonSectionController::class, 'store']);
                    Route::put('/teacher/lessons/{lesson}/sections/reorder', [LessonSectionController::class, 'reorder']);
                    Route::put('/teacher/lessons/{lesson}/sections/{section}', [LessonSectionController::class, 'update']);
                    Route::delete('/teacher/lessons/{lesson}/sections/{section}', [LessonSectionController::class, 'destroy']);
                });

                // Recursive content packages. Base path `content-packages` —
                // `/teacher/packages` is Billing's subscription plans (D13-1).
                Route::middleware('can:content.packages.manage')->group(function (): void {
                    Route::post('/teacher/content-packages', [ContentPackageController::class, 'store']);
                    Route::put('/teacher/content-packages/{package}', [ContentPackageController::class, 'update']);
                    Route::delete('/teacher/content-packages/{package}', [ContentPackageController::class, 'destroy']);
                    Route::post('/teacher/content-packages/{package}/items', [ContentPackageController::class, 'storeItem']);
                    Route::put('/teacher/content-packages/{package}/items/reorder', [ContentPackageController::class, 'reorderItems']);
                    Route::delete('/teacher/content-packages/{package}/items/{item}', [ContentPackageController::class, 'destroyItem']);
                });

                // Package types (B27) — content-package categories, scoped to the
                // active year (a type from another year/tenant 404s).
                Route::middleware('can:content.package_types.manage')->group(function (): void {
                    Route::post('/teacher/package-types', [PackageTypeController::class, 'store']);
                    Route::put('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'update']);
                    Route::delete('/teacher/package-types/{packageType:uuid}', [PackageTypeController::class, 'destroy']);
                });
            });

            Route::get('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'index'])->middleware('can:content.view');
            Route::middleware('can:content.lesson_attachments.manage')->group(function (): void {
                Route::post('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store']);
                Route::delete('/teacher/lessons/{lesson}/attachments/{attachment:uuid}', [LessonAttachmentController::class, 'destroy']);
            });

            // Lesson time-box config (availability window + extension allowance).
            Route::get('/teacher/lessons/{lesson}/availability', [LessonAvailabilityController::class, 'show'])->middleware('can:content.view');
            Route::put('/teacher/lessons/{lesson}/availability', [LessonAvailabilityController::class, 'update'])->middleware('can:content.lesson_availability.manage');
            // Open a lesson for one student for a custom number of hours (doc 11 R4).
            // Its own key: reopening touches one student's access, not the lesson.
            Route::post('/teacher/lessons/{lesson}/reopen', [LessonAvailabilityController::class, 'reopen'])->middleware('can:content.lessons.reopen');

            // Student extension requests — staff review + grant/deny.
            Route::middleware('can:content.extension_requests.review')->group(function (): void {
                Route::get('/teacher/extension-requests', [ExtensionRequestController::class, 'index']);
                Route::post('/teacher/extension-requests/{extensionRequest}/grant', [ExtensionRequestController::class, 'grant']);
                Route::post('/teacher/extension-requests/{extensionRequest}/deny', [ExtensionRequestController::class, 'deny']);
            });

            // Self-hosted video (M04) — upload, transcode, status.
            Route::post('/teacher/media/uploads', [TeacherMediaController::class, 'startUpload'])->middleware('can:content.media.upload');
            Route::post('/teacher/media/uploads/{media:uuid}/complete', [TeacherMediaController::class, 'completeUpload'])->middleware('can:content.media.upload');
            Route::get('/teacher/media/{media:uuid}', [TeacherMediaController::class, 'show'])->middleware('can:content.view');
            // Teacher self-preview: same encrypted-HLS flow (manifest_url + key_url).
            Route::post('/teacher/media/{media:uuid}/preview', [TeacherMediaController::class, 'preview'])->middleware('can:content.view');

            // Remote (OVH Media Host) video lifecycle — active when MEDIA_PROVIDER=remote.
            // Bound models are tenant-scoped, so cross-tenant ids resolve to 404.
            Route::post('/teacher/remote-videos/uploads', [RemoteVideoController::class, 'startUpload'])->middleware('can:content.media.upload');
            Route::post('/teacher/remote-videos/uploads/{session}/complete', [RemoteVideoController::class, 'complete'])->middleware('can:content.media.upload');
            Route::get('/teacher/remote-videos/{media:uuid}', [RemoteVideoController::class, 'show'])->middleware('can:content.view');
            Route::middleware('can:content.media.manage')->group(function (): void {
                Route::post('/teacher/remote-videos/{media:uuid}/replace', [RemoteVideoController::class, 'replace']);
                Route::post('/teacher/remote-videos/versions/{version}/retry', [RemoteVideoController::class, 'retry']);
                Route::post('/teacher/remote-videos/versions/{version}/quarantine', [RemoteVideoController::class, 'quarantine']);
                Route::post('/teacher/remote-videos/versions/{version}/restore', [RemoteVideoController::class, 'restore']);
                Route::delete('/teacher/remote-videos/versions/{version}', [RemoteVideoController::class, 'purge']);
            });

            // Academic years — READ side. The one route on this surface gated by
            // membership kind rather than a permission: the year selector is
            // request CONTEXT that every panel screen needs (year-scoped routes
            // 422 without X-Academic-Year), so a staff member who cannot list the
            // years cannot use any screen at all. What it exposes is only the
            // grade names the academy already publishes on its landing page.
            Route::middleware('role:teacher,assistant')->group(function (): void {
                Route::get('/teacher/academic-years', [AcademicYearController::class, 'index']);
                Route::get('/teacher/academic-years/{academicYear:uuid}', [AcademicYearController::class, 'show']);
            });

            // ── Finance (M20) ────────────────────────────────────────────
            // Coupons & promo codes (M21) — discounts applied at checkout, so
            // authoring one is a money power, kept apart from reading reports.
            Route::middleware('can:finance.coupons.manage')->group(function (): void {
                Route::get('/teacher/coupons', [CouponController::class, 'index']);
                Route::post('/teacher/coupons', [CouponController::class, 'store']);
                Route::get('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'show']);
                Route::put('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'update']);
                Route::delete('/teacher/coupons/{coupon:uuid}', [CouponController::class, 'destroy']);
            });

            // The academy's own plan + the plans it could switch to. Read-only:
            // changing a plan is admin-driven (docs/api/billing.md).
            Route::middleware('can:finance.subscription.view')->group(function (): void {
                Route::get('/teacher/subscription', [SubscriptionController::class, 'show']);
                Route::get('/teacher/packages', [TeacherPackageController::class, 'index']);
            });

            // ── Reports (M20) ────────────────────────────────────────────
            // Revenue and roster numbers are the academy's business figures; the
            // activity log is who-did-what. Different keys because they answer to
            // different jobs — a bookkeeper is not an auditor.
            Route::middleware('can:reports.view')->group(function (): void {
                Route::get('/teacher/reports/students', [TeacherReportsController::class, 'students']);
                Route::get('/teacher/reports/overview', [TeacherReportsController::class, 'overview']);
            });

            // The sales ledger names the student and the amount on every line, so
            // it is a money read rather than a headline figure: its own key.
            Route::middleware('can:finance.sales.view')->group(function (): void {
                // The dashboard's revenue widget (headline figures only).
                Route::get('/teacher/reports/sales', [TeacherReportsController::class, 'sales']);

                // The ledger proper: one row per transaction, filters, totals for
                // the active filter, and the same slice as a spreadsheet. Wallet
                // top-ups are reported by /topups and never inside the sales total.
                Route::get('/teacher/sales', [SalesLedgerController::class, 'index']);
                Route::get('/teacher/sales/filters', [SalesLedgerController::class, 'filters']);
                Route::get('/teacher/sales/topups', [SalesLedgerController::class, 'topups']);
                Route::get('/teacher/sales/export', [SalesLedgerController::class, 'export']);
                Route::get('/teacher/orders/{order:uuid}/refunds', [RefundController::class, 'index']);
            });

            // Moving money back — and taking back the access it bought — is a
            // heavier power than reading the books, so it answers to its own key.
            Route::post('/teacher/orders/{order:uuid}/refunds', [RefundController::class, 'store'])
                ->middleware('can:finance.refunds.manage');

            Route::get('/teacher/audit-logs', [AuditLogController::class, 'teacher'])->middleware('can:reports.audit_log.view');

            // ── Centers (M20) ────────────────────────────────────────────
            // Branches, attendance, codes. The split that matters here is between
            // running the door and running the branch: a reception desk records
            // check-ins all day and must not be able to delete a center or mint
            // recharge codes.
            Route::middleware('can:centers.view')->group(function (): void {
                Route::get('/teacher/centers', [CenterController::class, 'index']);
            });

            Route::post('/teacher/centers', [CenterController::class, 'store'])->middleware('can:centers.create');
            Route::put('/teacher/centers/{center:uuid}', [CenterController::class, 'update'])->middleware('can:centers.update');
            Route::delete('/teacher/centers/{center:uuid}', [CenterController::class, 'destroy'])->middleware('can:centers.delete');
            // Offline sync pushes a batch of edits made on a branch device.
            Route::post('/teacher/centers/sync', CenterSyncController::class)->middleware('can:centers.update');

            Route::get('/teacher/centers/{center:uuid}/attendance', [AttendanceController::class, 'index'])->middleware('can:centers.attendance.view');
            Route::post('/teacher/centers/{center:uuid}/attendance', [AttendanceController::class, 'store'])->middleware('can:centers.attendance.record');

            // Activation/recharge codes — a batch is worth real money, so issuing
            // is its own key, apart from reading the list or disabling one.
            Route::get('/teacher/codes', [ActivationCodeController::class, 'index'])->middleware('can:centers.activation_codes.view');
            Route::post('/teacher/codes/batch', [ActivationCodeController::class, 'batch'])->middleware('can:centers.activation_codes.issue');
            Route::post('/teacher/codes/{code:uuid}/disable', [ActivationCodeController::class, 'disable'])->middleware('can:centers.activation_codes.disable');

            // Center ID-codes (B20) — sequential, grade-encoded student-identity
            // codes minted per center; a sibling of /codes, NOT the recharge codes.
            // Year-scoped (X-Academic-Year): the panel's year selector filters the
            // list, and a batch is stamped with the active academic year.
            Route::middleware(['academic-year', 'can:centers.id_codes.manage'])->group(function (): void {
                Route::get('/teacher/center-id-codes', [CenterIdCodeController::class, 'index']);
                Route::post('/teacher/center-id-codes/batch', [CenterIdCodeController::class, 'batch']);
            });

            // Center sessions (a session bundles 0+ lessons) + session-based
            // attendance: a check-in opens all the session's lessons online.
            // Year-scoped: sessions/lessons/enrollments live under a year.
            Route::middleware('academic-year')->group(function (): void {
                Route::middleware('can:centers.sessions.manage')->group(function (): void {
                    Route::get('/teacher/center-sessions', [CenterSessionController::class, 'index']);
                    Route::post('/teacher/center-sessions', [CenterSessionController::class, 'store']);
                    Route::put('/teacher/center-sessions/{session}', [CenterSessionController::class, 'update']);
                    Route::delete('/teacher/center-sessions/{session}', [CenterSessionController::class, 'destroy']);
                });

                // The check-in desk: reading the roster is the view key, marking a
                // student present is the record key, and undoing a check-in — which
                // closes the lessons it opened — is a third.
                Route::middleware('can:centers.attendance.view')->group(function (): void {
                    Route::get('/teacher/attendance/active', [SessionAttendanceController::class, 'active']);
                    Route::get('/teacher/attendance/roster', [SessionAttendanceController::class, 'roster']);
                });
                Route::post('/teacher/attendance/checkin', [SessionAttendanceController::class, 'checkin'])->middleware('can:centers.attendance.record');
                Route::delete('/teacher/attendance/active/{record}', [SessionAttendanceController::class, 'revoke'])->middleware('can:centers.attendance.revoke');
            });

            // Center paper-exam grade entry (VD R12, doc 13 Phase 15). A grade
            // belongs to an academic year, so these are year-scoped; {grade} binds
            // by uuid within the active year.
            Route::middleware(['academic-year', 'can:centers.exam_grades.manage'])->group(function (): void {
                Route::get('/teacher/center-exam-grades', [CenterExamGradeController::class, 'index']);
                Route::post('/teacher/center-exam-grades', [CenterExamGradeController::class, 'store']);
                Route::put('/teacher/center-exam-grades/{grade:uuid}', [CenterExamGradeController::class, 'update']);
                Route::delete('/teacher/center-exam-grades/{grade:uuid}', [CenterExamGradeController::class, 'destroy']);
            });

            // ── Exams (M20) ──────────────────────────────────────────────
            // Authoring and grading are different jobs and now different keys: a
            // grader reads submissions and scores them without being able to
            // rewrite the paper, and an author writes the paper without seeing
            // who failed it. `exams.view` is the read floor.
            Route::middleware('can:exams.view')->group(function (): void {
                // `type` drives the link + auto-fill; filter the index by
                // ?type=&lesson_id= (`courses`/units retired — VD §7).
                Route::get('/teacher/exams', [ExamController::class, 'index']);
                Route::get('/teacher/exams/{exam:uuid}', [ExamController::class, 'show']);
                Route::get('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'index']);
                // Link-target dropdown for the exam editor (lesson picker).
                Route::get('/teacher/exam-link/lessons', [ExamLinkController::class, 'lessons']);
            });

            Route::post('/teacher/exams', [ExamController::class, 'store'])->middleware('can:exams.create');
            Route::put('/teacher/exams/{exam:uuid}', [ExamController::class, 'update'])->middleware('can:exams.update');
            Route::delete('/teacher/exams/{exam:uuid}', [ExamController::class, 'destroy'])->middleware('can:exams.delete');

            Route::middleware('can:exams.questions.manage')->group(function (): void {
                Route::post('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'store']);
                Route::put('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'update']);
                Route::delete('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'destroy']);
            });

            // On-site bubble-sheet MCQ builder (doc 13 Phase 7) — read/replace the
            // whole answer sheet at once. Year-scoped, like lesson/part authoring.
            // The sheet IS the answer key, so reading it needs the same key as
            // writing it — `exams.view` is not enough.
            Route::middleware('academic-year')->group(function (): void {
                Route::middleware('can:exams.bubble_sheet.manage')->group(function (): void {
                    Route::get('/teacher/exams/{exam:uuid}/bubble-sheet', [BubbleSheetController::class, 'show']);
                    Route::put('/teacher/exams/{exam:uuid}/bubble-sheet', [BubbleSheetController::class, 'update']);
                });
            });

            // Exam/quiz time-extension requests — staff review (doc 11 R6).
            Route::middleware('can:exams.extensions.review')->group(function (): void {
                Route::get('/teacher/exam-extension-requests', [ExamExtensionRequestController::class, 'index']);
                Route::post('/teacher/exam-extension-requests/{examExtension}/grant', [ExamExtensionRequestController::class, 'grant']);
                Route::post('/teacher/exam-extension-requests/{examExtension}/deny', [ExamExtensionRequestController::class, 'deny']);
            });

            // Grading (doc 11 R3.4) — reviewing what students submitted, and
            // scoring it. A submission carries the student's own work, so reading
            // one is its own key, separate from seeing the exam.
            Route::middleware('can:exams.submissions.view')->group(function (): void {
                Route::get('/teacher/exams/{exam:uuid}/submissions', [ExamGradingController::class, 'submissions']);
                Route::get('/teacher/exams/{exam:uuid}/attempts/{attempt}/files/{question}', [ExamGradingController::class, 'downloadFile']);
            });

            Route::post('/teacher/exams/{exam:uuid}/attempts/{attempt}/grade', [ExamGradingController::class, 'grade'])->middleware('can:exams.grade');

            // Manual pass-override on a must_pass part (VD change set §7 LP-D3).
            // Year-scoped like the rest of lesson authoring.
            Route::middleware(['academic-year', 'can:exams.pass_override'])->group(function (): void {
                Route::post('/teacher/lessons/{lesson}/sections/{section}/pass-override', [LessonSectionController::class, 'storePassOverride']);
                // {user} is resolved independently of {section} — the controller
                // scopes the delete by (section, user). Without this, Laravel
                // auto-scopes the child and tries LessonSection::users() → 500.
                Route::delete('/teacher/lessons/{lesson}/sections/{section}/pass-override/{user:uuid}', [LessonSectionController::class, 'destroyPassOverride'])
                    ->withoutScopedBindings();
            });

            // ── Students (M20) ───────────────────────────────────────────
            // One key per action. Reading a roster, moving money in a wallet and
            // deleting a student are different powers and are granted separately;
            // `students.view` is the read floor the rest sit on.
            Route::middleware('can:students.view')->group(function (): void {
                Route::get('/teacher/students', [StudentController::class, 'index']);
                Route::get('/teacher/students/{student:uuid}', [StudentController::class, 'show']);
                Route::get('/teacher/students/{student:uuid}/enrollments', [StudentEnrollmentController::class, 'index']);
                Route::get('/teacher/students/{student:uuid}/content-overrides', [StudentContentOverrideController::class, 'index']);
                Route::get('/teacher/students/{student:uuid}/parents', [StudentParentController::class, 'index']);
            });

            Route::post('/teacher/students', [StudentController::class, 'store'])->middleware('can:students.create');
            Route::patch('/teacher/students/{student:uuid}', [StudentController::class, 'update'])->middleware('can:students.update');
            Route::delete('/teacher/students/{student:uuid}', [StudentController::class, 'destroy'])->middleware('can:students.delete');

            // Bulk student-history import (.xlsx/.csv) — matched by phone/email.
            // Its own key: an import writes hundreds of rows in one call.
            Route::post('/teacher/students/import', StudentImportController::class)->middleware('can:students.import');
            Route::get('/teacher/students/{student:uuid}/export', [StudentController::class, 'export'])->middleware('can:students.export');
            Route::post('/teacher/students/{student:uuid}/reset-password', [StudentController::class, 'resetPassword'])->middleware('can:students.reset_password');

            // Manual content-access overrides — grant/revoke a student direct
            // access to a locked lesson/section/unit, bypassing dependencies.
            Route::middleware('can:students.content_overrides.manage')->group(function (): void {
                Route::post('/teacher/students/{student:uuid}/content-overrides', [StudentContentOverrideController::class, 'store']);
                Route::delete('/teacher/students/{student:uuid}/content-overrides/{override}', [StudentContentOverrideController::class, 'destroy']);
            });

            // Access (enrollments)
            Route::middleware('can:students.enrollments.manage')->group(function (): void {
                Route::post('/teacher/students/{student:uuid}/enrollments', [StudentEnrollmentController::class, 'store']);
                Route::delete('/teacher/students/{student:uuid}/enrollments/{enrollment}', [StudentEnrollmentController::class, 'destroy']);
            });

            // Money — reading a balance and changing one are deliberately split.
            Route::middleware('can:students.wallet.view')->group(function (): void {
                Route::get('/teacher/students/{student:uuid}/wallet', [StudentFinanceController::class, 'wallet']);
                Route::get('/teacher/students/{student:uuid}/wallet/ledger', [StudentFinanceController::class, 'ledger']);
                Route::get('/teacher/students/{student:uuid}/orders', [StudentFinanceController::class, 'orders']);
            });
            Route::middleware('can:students.wallet.adjust')->group(function (): void {
                Route::post('/teacher/students/{student:uuid}/wallet/adjust', [StudentFinanceController::class, 'adjust']);
                Route::post('/teacher/students/{student:uuid}/wallet/set', [StudentFinanceController::class, 'setBalance']);
            });

            // Activity
            Route::middleware('can:students.activity.view')->group(function (): void {
                Route::get('/teacher/students/{student:uuid}/progress', [StudentActivityController::class, 'progress']);
                Route::get('/teacher/students/{student:uuid}/activity', [StudentActivityController::class, 'history']);
                // Study & performance tab: this student's center attendance and
                // every score they hold (online attempts + paper center grades).
                Route::get('/teacher/students/{student:uuid}/attendance', [StudentActivityController::class, 'attendance']);
                Route::get('/teacher/students/{student:uuid}/exam-results', [StudentActivityController::class, 'examResults']);
            });
            Route::post('/teacher/students/{student:uuid}/notify', [StudentActivityController::class, 'notify'])->middleware('can:students.notify');

            // Parents (M13). `parent` is resolved independently of `student` — the
            // controller already scopes by (student, parent). Without this, Laravel
            // auto-enables scoped binding for the custom-key child and tries to
            // resolve it via a nonexistent User::parents() relationship (500).
            Route::middleware('can:students.parents.manage')->group(function (): void {
                Route::post('/teacher/students/{student:uuid}/parents', [StudentParentController::class, 'store']);
                Route::delete('/teacher/students/{student:uuid}/parents/{parent:uuid}', [StudentParentController::class, 'destroy'])
                    ->withoutScopedBindings();
                // Re-issue a linked parent's password.
                Route::post('/teacher/students/{student:uuid}/parents/{parent:uuid}/reset-password', [StudentParentController::class, 'resetPassword'])
                    ->withoutScopedBindings();
                // Passwordless magic link (VD R11): issue (rotates) / revoke.
                Route::post('/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link', [StudentParentController::class, 'magicLink'])
                    ->withoutScopedBindings();
                Route::delete('/teacher/students/{student:uuid}/parents/{parent:uuid}/magic-link', [StudentParentController::class, 'revokeMagicLink'])
                    ->withoutScopedBindings();
            });

            // Manual payment-receipt verification (VD R9/R10) — teacher, or an
            // assistant granted `finance`, reviews manual wallet top-ups. Tenant-level,
            // NOT year-scoped (no X-Academic-Year).
            // Reading a receipt and deciding it are the same job (someone who can
            // see the proof of payment is the one who accepts or refuses it), so
            // all four share `finance.receipts.review`.
            Route::middleware('can:finance.receipts.review')->group(function (): void {
                Route::get('/teacher/payment-receipts', [PaymentReceiptController::class, 'index']);
                Route::get('/teacher/payment-receipts/{receipt:uuid}', [PaymentReceiptController::class, 'show']);
                Route::post('/teacher/payment-receipts/{receipt:uuid}/approve', [PaymentReceiptController::class, 'approve']);
                Route::post('/teacher/payment-receipts/{receipt:uuid}/reject', [PaymentReceiptController::class, 'reject']);
            });

            // Support tickets — staff side (M09, B25 / VD Item 11). Teacher, or an
            // assistant granted `support`, lists every ticket (filter ?status=
            // &priority=), reads a thread, replies (notifies the student), and
            // moves the status. {ticket} binds by uuid, tenant-scoped (no owner
            // check — staff see the whole tenant). Student side: /support/tickets.
            Route::middleware('can:support.view')->group(function (): void {
                Route::get('/teacher/support/tickets', [TeacherSupportTicketController::class, 'index']);
                Route::get('/teacher/support/tickets/{ticket}', [TeacherSupportTicketController::class, 'show']);
            });

            // Replying speaks to the student in the academy's name, and closing a
            // ticket ends the conversation — separate powers from reading one.
            Route::post('/teacher/support/tickets/{ticket}/replies', [TeacherSupportTicketController::class, 'reply'])->middleware('can:support.reply');
            Route::patch('/teacher/support/tickets/{ticket}/status', [TeacherSupportTicketController::class, 'updateStatus'])->middleware('can:support.status.change');
        }); // permission-gated shared surface
    });
});
