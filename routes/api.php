<?php

use App\Modules\Assessment\Http\Controllers\AttemptController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamController;
use App\Modules\Assessment\Http\Controllers\Teacher\ExamGradingController;
use App\Modules\Assessment\Http\Controllers\Teacher\QuestionController;
use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Catalog\Http\Controllers\Teacher\CategoryController;
use App\Modules\Catalog\Http\Controllers\Teacher\CourseController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonAttachmentController;
use App\Modules\Catalog\Http\Controllers\Teacher\LessonController;
use App\Modules\Catalog\Http\Controllers\Teacher\UnitController;
use App\Modules\Centers\Http\Controllers\RedeemCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\ActivationCodeController;
use App\Modules\Centers\Http\Controllers\Teacher\AttendanceController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterController;
use App\Modules\Centers\Http\Controllers\Teacher\CenterSyncController;
use App\Modules\Commerce\Http\Controllers\CheckoutController;
use App\Modules\Commerce\Http\Controllers\PaymentWebhookController;
use App\Modules\Engagement\Http\Controllers\FavoriteController;
use App\Modules\Engagement\Http\Controllers\GamificationController;
use App\Modules\Engagement\Http\Controllers\ProgressController;
use App\Modules\Engagement\Http\Controllers\ReviewController;
use App\Modules\Engagement\Http\Controllers\Teacher\BadgeController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\ParentController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentActivityController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentEnrollmentController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentFinanceController;
use App\Modules\Identity\Http\Controllers\Teacher\StudentParentController;
use App\Modules\Media\Http\Controllers\InternalMediaController;
use App\Modules\Media\Http\Controllers\PlaybackController;
use App\Modules\Media\Http\Controllers\TeacherMediaController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminReportController;
use App\Modules\PlatformAdmin\Http\Controllers\AdminTenantController;
use App\Modules\Reporting\Http\Controllers\AuditLogController;
use App\Modules\Reporting\Http\Controllers\StudentCoursesController;
use App\Modules\Reporting\Http\Controllers\TeacherReportsController;
use App\Modules\Tenancy\Http\Controllers\TeacherLandingController;
use App\Modules\Tenancy\Http\Controllers\TeacherProfileController;
use App\Modules\Tenancy\Http\Controllers\TenantContextController;
use App\Modules\Tenancy\Http\Controllers\TenantLandingController;
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
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::get('/admin/tenants', [AdminTenantController::class, 'index']);
    Route::post('/admin/tenants', [AdminTenantController::class, 'store']);
    Route::get('/admin/tenants/{tenant:uuid}', [AdminTenantController::class, 'show']);
    Route::put('/admin/tenants/{tenant:uuid}', [AdminTenantController::class, 'update']);
    Route::get('/admin/reports/overview', [AdminReportController::class, 'overview']);
    Route::get('/admin/audit-logs', [AuditLogController::class, 'admin']);
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

    // Tenant context & branding
    Route::get('/tenant/context', TenantContextController::class);
    // Public landing page (resolved: layout + nav + sections). Optional auth → `enrolled`.
    Route::get('/tenant/landing', TenantLandingController::class);

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
        Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('/checkout/order', [CheckoutController::class, 'order']);
        Route::post('/checkout/pay', [CheckoutController::class, 'pay'])->middleware('throttle:auth');

        // Protected playback (M04, M22) — authorize issues a short-lived token.
        Route::post('/media/lessons/{lesson}/playback', [PlaybackController::class, 'authorize']);

        // Progress (M10, M20)
        Route::post('/lessons/{lesson}/progress', [ProgressController::class, 'store']);
        Route::get('/me/activity', [ProgressController::class, 'activity']);
        Route::get('/me/resume', [ProgressController::class, 'resume']);

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

        // Exams & assignments — student side (M08)
        Route::get('/exams', [AttemptController::class, 'index']);
        Route::post('/exams/{exam:uuid}/attempts', [AttemptController::class, 'start']);
        Route::post('/exams/{exam:uuid}/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
        Route::get('/exams/{exam:uuid}/attempts/{attempt}', [AttemptController::class, 'result']);

        Route::get('/me/courses', [StudentCoursesController::class, 'index']);

        // Parent portal (M13) — parent role in the current tenant
        Route::middleware('role:parent')->group(function (): void {
            Route::get('/parent/children', [ParentController::class, 'children']);
            Route::get('/parent/children/{student:uuid}/progress', [ParentController::class, 'progress']);
            Route::get('/parent/children/{student:uuid}/results', [ParentController::class, 'results']);
        });

        // Teacher site & identity (M02) — teacher role in the current tenant
        Route::middleware('role:teacher')->group(function (): void {
            Route::get('/teacher/profile', [TeacherProfileController::class, 'show']);
            Route::put('/teacher/profile', [TeacherProfileController::class, 'update']);
            Route::get('/teacher/landing', [TeacherLandingController::class, 'show']);
            Route::put('/teacher/landing', [TeacherLandingController::class, 'update']);
            Route::post('/teacher/landing/media', [TeacherLandingController::class, 'media']);

            // Catalog (M04) — course taxonomy + structure. Courses bind by uuid
            // (no id enumeration); nested units/lessons bind by id (own data).
            Route::get('/teacher/categories', [CategoryController::class, 'index']);
            Route::post('/teacher/categories', [CategoryController::class, 'store']);
            Route::put('/teacher/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/teacher/categories/{category}', [CategoryController::class, 'destroy']);

            Route::get('/teacher/courses', [CourseController::class, 'index']);
            Route::post('/teacher/courses', [CourseController::class, 'store']);
            Route::get('/teacher/courses/{course:uuid}', [CourseController::class, 'show']);
            Route::put('/teacher/courses/{course:uuid}', [CourseController::class, 'update']);
            Route::delete('/teacher/courses/{course:uuid}', [CourseController::class, 'destroy']);

            Route::get('/teacher/courses/{course:uuid}/units', [UnitController::class, 'index']);
            Route::post('/teacher/courses/{course:uuid}/units', [UnitController::class, 'store']);
            Route::put('/teacher/courses/{course:uuid}/units/{unit}', [UnitController::class, 'update']);
            Route::delete('/teacher/courses/{course:uuid}/units/{unit}', [UnitController::class, 'destroy']);

            Route::get('/teacher/units/{unit}/lessons', [LessonController::class, 'index']);
            Route::post('/teacher/units/{unit}/lessons', [LessonController::class, 'store']);
            Route::put('/teacher/units/{unit}/lessons/{lesson}', [LessonController::class, 'update']);
            Route::delete('/teacher/units/{unit}/lessons/{lesson}', [LessonController::class, 'destroy']);

            Route::get('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'index']);
            Route::post('/teacher/lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store']);
            Route::delete('/teacher/lessons/{lesson}/attachments/{attachment:uuid}', [LessonAttachmentController::class, 'destroy']);

            // Self-hosted video (M04) — upload → transcode → status.
            Route::post('/teacher/media/uploads', [TeacherMediaController::class, 'startUpload']);
            Route::post('/teacher/media/uploads/{media:uuid}/complete', [TeacherMediaController::class, 'completeUpload']);
            Route::get('/teacher/media/{media:uuid}', [TeacherMediaController::class, 'show']);
            // Teacher self-preview → same encrypted-HLS flow (returns manifest_url + key_url).
            Route::post('/teacher/media/{media:uuid}/preview', [TeacherMediaController::class, 'preview']);

            // Exams & assignments — teacher authoring + grading (M08)
            Route::get('/teacher/courses/{course:uuid}/exams', [ExamController::class, 'index']);
            Route::post('/teacher/courses/{course:uuid}/exams', [ExamController::class, 'store']);
            Route::get('/teacher/exams/{exam:uuid}', [ExamController::class, 'show']);
            Route::put('/teacher/exams/{exam:uuid}', [ExamController::class, 'update']);
            Route::delete('/teacher/exams/{exam:uuid}', [ExamController::class, 'destroy']);

            Route::get('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'index']);
            Route::post('/teacher/exams/{exam:uuid}/questions', [QuestionController::class, 'store']);
            Route::put('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'update']);
            Route::delete('/teacher/exams/{exam:uuid}/questions/{question}', [QuestionController::class, 'destroy']);

            Route::get('/teacher/exams/{exam:uuid}/submissions', [ExamGradingController::class, 'submissions']);
            Route::post('/teacher/exams/{exam:uuid}/attempts/{attempt}/grade', [ExamGradingController::class, 'grade']);

            // Gamification (M19) — badges + ranking toggle
            Route::get('/teacher/badges', [BadgeController::class, 'index']);
            Route::post('/teacher/badges', [BadgeController::class, 'store']);
            Route::delete('/teacher/badges/{badge}', [BadgeController::class, 'destroy']);
            Route::get('/teacher/gamification', [BadgeController::class, 'settings']);
            Route::put('/teacher/gamification', [BadgeController::class, 'updateSettings']);

            // Teacher reports (M17, basic)
            Route::get('/teacher/reports/sales', [TeacherReportsController::class, 'sales']);
            Route::get('/teacher/reports/students', [TeacherReportsController::class, 'students']);

            // Audit log (M18)
            Route::get('/teacher/audit-logs', [AuditLogController::class, 'teacher']);

            // Centers (M12) — branches, activation codes, attendance, offline sync
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

            // Students (M17) — the teacher's full control over their own students.
            Route::get('/teacher/students', [StudentController::class, 'index']);
            Route::post('/teacher/students', [StudentController::class, 'store']);
            Route::get('/teacher/students/{student:uuid}', [StudentController::class, 'show']);
            Route::patch('/teacher/students/{student:uuid}', [StudentController::class, 'update']);
            Route::delete('/teacher/students/{student:uuid}', [StudentController::class, 'destroy']);
            Route::post('/teacher/students/{student:uuid}/reset-password', [StudentController::class, 'resetPassword']);
            Route::get('/teacher/students/{student:uuid}/export', [StudentController::class, 'export']);

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
            Route::delete('/teacher/students/{student:uuid}/parents/{parent:uuid}', [StudentParentController::class, 'destroy']);
        });
    });
});
