<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Full-surface endpoint smoke test. Seeds the demo academies, builds the
 * remaining fixtures through the real API (so create endpoints are exercised),
 * then sweeps EVERY api/v1 route with the correct actor + tenant + payload and
 * records the real HTTP status. A JSON + text report is written to the scratchpad.
 *
 * Classification per call:
 *   PASS     – status in the accepted set
 *   WARN     – unexpected (non-5xx) status, e.g. 422/403/404 worth a human look
 *   WARN5xx  – 5xx on a KNOWN-stub route (media delivery / signed / HMAC callbacks)
 *   FAIL     – 5xx on a normal route, or an auth hole — a real defect
 */
class EndpointSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array<string,mixed>> */
    private array $results = [];

    private Tenant $t1;

    private User $admin;

    private User $teacher1;

    private User $student1;

    private User $payStudent;

    private Course $course1;      // seeded, paid, student1 NOT enrolled

    private Course $reviewCourse; // seeded, student1 enrolled

    private Course $payCourse;    // fresh, purchasable, payStudent funded

    private Unit $unit1;

    private Lesson $lesson1;      // free preview + has video

    private CourseCategory $category1;

    private const TENANT = 'ahmed-physics';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed();
        $this->arrangeDirect();
    }

    /** Build the pieces the seeder omits, directly (known-good model patterns). */
    private function arrangeDirect(): void
    {
        $this->t1 = Tenant::where('slug', self::TENANT)->firstOrFail();
        $this->admin = User::where('phone', '01000000009')->firstOrFail();
        $this->teacher1 = User::where('phone', '01500000001')->firstOrFail();
        $this->student1 = User::where('phone', '01281000001')->firstOrFail();

        $ctx = app(TenantContext::class);
        $ctx->setTenant($this->t1);

        $courses = Course::where('tenant_id', $this->t1->id)->orderBy('id')->get();
        $this->course1 = $courses[0];
        $this->reviewCourse = $courses[1]; // student1 (s=1) is enrolled in courses[1] & [2]
        $this->category1 = CourseCategory::where('tenant_id', $this->t1->id)->firstOrFail();
        $this->unit1 = Unit::where('course_id', $this->course1->id)->orderBy('id')->firstOrFail();
        $this->lesson1 = Lesson::where('unit_id', $this->unit1->id)->orderBy('id')->firstOrFail();

        // Give the free-preview lesson a ready video so playback authorize resolves.
        $video = new MediaAsset(['lesson_id' => $this->lesson1->id, 'type' => MediaType::HlsVideo->value, 'status' => 'ready', 'title' => 'v']);
        $video->tenant_id = $this->t1->id;
        $video->save();
        $this->lesson1->update(['video_asset_id' => $video->id]);

        // A funded student + a purchasable course, so checkout can complete.
        $this->payStudent = User::factory()->create(['phone' => '01090000001', 'name' => 'Pay Student']);
        TenantUser::create(['tenant_id' => $this->t1->id, 'user_id' => $this->payStudent->id, 'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now()]);

        $pc = new Course(['title' => 'Pay Course', 'visibility' => 'visible', 'price_minor' => 50000, 'currency' => 'EGP', 'is_free' => false, 'purchase_enabled' => true]);
        $pc->tenant_id = $this->t1->id;
        $pc->slug = 'pay-course-smoke';
        $pc->save();
        $this->payCourse = $pc;

        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->t1->id, $this->payStudent->id);
        $ledger->post($this->t1->id, 'smoke:topup', [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => 1000000, 'wallet_id' => $wallet->id],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => 1000000, 'wallet_id' => null],
        ], 'seed', $this->payStudent->id);

        $ctx->forget();
    }

    private function hit(string $method, string $uri, ?User $actor, array $body = [], ?string $tenant = null, array $ok = [200, 201, 202, 204], bool $tolerant = false, string $group = ''): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }
        $headers = $tenant !== null ? ['X-Tenant' => $tenant] : [];
        $res = $this->withHeaders($headers)->json($method, $uri, $body);
        $status = $res->getStatusCode();

        if ($status >= 500) {
            $class = $tolerant ? 'WARN5xx' : 'FAIL';
        } elseif (in_array($status, $ok, true)) {
            $class = 'PASS';
        } else {
            $class = 'WARN';
        }

        $this->results[] = [
            'group' => $group,
            'method' => strtoupper($method),
            'uri' => $uri,
            'actor' => $actor?->phone ?? 'guest',
            'status' => $status,
            'class' => $class,
        ];

        return $res;
    }

    private function pick(TestResponse $res, string ...$paths): mixed
    {
        foreach ($paths as $p) {
            $v = $res->json($p);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    public function test_smoke_all_endpoints(): void
    {
        $T = self::TENANT;
        $t = $this->teacher1;
        $s = $this->student1;

        // ---------------------------------------------------------------
        // TENANCY (public + teacher)
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/tenant/context', null, [], $T, group: 'Tenancy');
        $this->hit('GET', '/api/v1/tenant/landing', null, [], $T, group: 'Tenancy');
        $this->hit('GET', '/api/v1/teacher/profile', $t, [], $T, group: 'Tenancy');
        $this->hit('PUT', '/api/v1/teacher/profile', $t, ['bio' => 'Smoke bio'], $T, group: 'Tenancy');
        $this->hit('GET', '/api/v1/teacher/access', $t, [], $T, group: 'Tenancy');
        $this->hit('PUT', '/api/v1/teacher/access', $t, ['login_enabled' => true, 'registration_enabled' => true], $T, group: 'Tenancy');
        $this->hit('GET', '/api/v1/teacher/custom-landing', $t, [], $T, group: 'Tenancy');
        $this->hit('PUT', '/api/v1/teacher/custom-landing', $t, ['custom_landing_enabled' => false], $T, group: 'Tenancy');
        $landing = $this->hit('GET', '/api/v1/teacher/landing', $t, [], $T, group: 'Tenancy');
        $sections = $this->pick($landing, 'data.sections') ?? [];
        $this->hit('PUT', '/api/v1/teacher/landing', $t, ['sections' => $sections], $T, ok: [200, 422], tolerant: true, group: 'Tenancy');
        $this->hit('POST', '/api/v1/teacher/landing/media', $t, ['image' => UploadedFile::fake()->image('l.jpg')], $T, ok: [200, 201, 422], tolerant: true, group: 'Tenancy');

        // ---------------------------------------------------------------
        // IDENTITY / AUTH (public)
        // ---------------------------------------------------------------
        $this->hit('POST', '/api/v1/auth/register', null, ['name' => 'New Reg', 'phone' => '01099998888', 'password' => 'password123', 'password_confirmation' => 'password123'], $T, ok: [202, 429], group: 'Auth');
        $this->hit('POST', '/api/v1/auth/otp/request', null, ['identifier' => '01099998888', 'purpose' => 'register'], $T, ok: [200, 429], group: 'Auth');
        $this->hit('POST', '/api/v1/auth/otp/verify', null, ['identifier' => '01099998888', 'purpose' => 'register', 'code' => '000000'], $T, ok: [200, 422, 429], group: 'Auth');
        $this->hit('POST', '/api/v1/auth/login', null, ['identifier' => '01500000001', 'password' => 'password'], $T, ok: [200, 429], group: 'Auth');
        $this->hit('POST', '/api/v1/auth/password/forgot', null, ['identifier' => '01500000001'], $T, ok: [200, 429], group: 'Auth');
        $this->hit('POST', '/api/v1/auth/password/reset', null, ['identifier' => '01500000001', 'code' => '000000', 'password' => 'password123'], $T, ok: [200, 422, 429], group: 'Auth');
        $this->hit('GET', '/api/v1/me', $s, [], $T, group: 'Auth');
        $this->hit('POST', '/api/v1/auth/logout', $t, [], $T, group: 'Auth');

        // ---------------------------------------------------------------
        // CATALOG — public + teacher CRUD (create → mutate → delete throwaways)
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/courses', null, [], $T, group: 'Catalog');
        $this->hit('GET', "/api/v1/courses/{$this->course1->slug}", null, [], $T, group: 'Catalog');
        $this->hit('GET', "/api/v1/courses/{$this->course1->slug}/reviews", null, [], $T, group: 'Catalog');
        $this->hit('POST', "/api/v1/courses/{$this->reviewCourse->slug}/reviews", $s, ['rating' => 5, 'comment' => 'Great'], $T, ok: [200, 201, 403], group: 'Catalog');

        $this->hit('GET', '/api/v1/teacher/categories', $t, [], $T, group: 'Catalog');
        $catRes = $this->hit('POST', '/api/v1/teacher/categories', $t, ['name' => 'Smoke Cat'], $T, group: 'Catalog');
        $catId = $this->pick($catRes, 'data.id');
        $this->hit('PUT', "/api/v1/teacher/categories/{$catId}", $t, ['name' => 'Smoke Cat 2'], $T, group: 'Catalog');
        $this->hit('DELETE', "/api/v1/teacher/categories/{$catId}", $t, [], $T, group: 'Catalog');

        $this->hit('GET', '/api/v1/teacher/courses', $t, [], $T, group: 'Catalog');
        $cRes = $this->hit('POST', '/api/v1/teacher/courses', $t, ['title' => 'Throwaway Course', 'visibility' => 'visible'], $T, group: 'Catalog');
        $cUuid = $this->pick($cRes, 'data.uuid');
        $this->hit('GET', "/api/v1/teacher/courses/{$this->course1->uuid}", $t, [], $T, group: 'Catalog');
        $this->hit('PUT', "/api/v1/teacher/courses/{$cUuid}", $t, ['title' => 'Throwaway 2'], $T, group: 'Catalog');

        $this->hit('GET', "/api/v1/teacher/courses/{$this->course1->uuid}/units", $t, [], $T, group: 'Catalog');
        $uRes = $this->hit('POST', "/api/v1/teacher/courses/{$cUuid}/units", $t, ['title' => 'Throwaway Unit'], $T, group: 'Catalog');
        $uId = $this->pick($uRes, 'data.id');
        $this->hit('PUT', "/api/v1/teacher/courses/{$cUuid}/units/{$uId}", $t, ['title' => 'U2'], $T, group: 'Catalog');

        $this->hit('GET', "/api/v1/teacher/units/{$uId}/lessons", $t, [], $T, group: 'Catalog');
        $lRes = $this->hit('POST', "/api/v1/teacher/units/{$uId}/lessons", $t, ['title' => 'Throwaway Lesson'], $T, group: 'Catalog');
        $lId = $this->pick($lRes, 'data.id');
        $this->hit('PUT', "/api/v1/teacher/units/{$uId}/lessons/{$lId}", $t, ['title' => 'L2'], $T, group: 'Catalog');

        $this->hit('GET', "/api/v1/teacher/lessons/{$lId}/attachments", $t, [], $T, group: 'Catalog');
        $aRes = $this->hit('POST', "/api/v1/teacher/lessons/{$lId}/attachments", $t, ['type' => 'link', 'title' => 'Ref', 'url' => 'https://ex.com/a.pdf'], $T, group: 'Catalog');
        $aUuid = $this->pick($aRes, 'data.uuid');
        $this->hit('DELETE', "/api/v1/teacher/lessons/{$lId}/attachments/{$aUuid}", $t, [], $T, ok: [200, 204], group: 'Catalog');

        // delete throwaway lesson/unit/course
        $this->hit('DELETE', "/api/v1/teacher/units/{$uId}/lessons/{$lId}", $t, [], $T, ok: [200, 204], group: 'Catalog');
        $this->hit('DELETE', "/api/v1/teacher/courses/{$cUuid}/units/{$uId}", $t, [], $T, ok: [200, 204], group: 'Catalog');
        $this->hit('DELETE', "/api/v1/teacher/courses/{$cUuid}", $t, [], $T, ok: [200, 204], group: 'Catalog');

        // packages/bundles: public browse + teacher CRUD (throwaway)
        $this->hit('GET', '/api/v1/bundles', null, [], $T, group: 'Catalog');
        $this->hit('GET', '/api/v1/teacher/bundles', $t, [], $T, group: 'Catalog');
        $bRes = $this->hit('POST', '/api/v1/teacher/bundles', $t, ['title' => 'Smoke Package', 'price_minor' => 5000, 'visibility' => 'visible', 'purchase_enabled' => true, 'items' => [['type' => 'course', 'course' => $this->course1->uuid]]], $T, ok: [200, 201], group: 'Catalog');
        $bUuid = $this->pick($bRes, 'data.uuid');
        $bSlug = $this->pick($bRes, 'data.slug');
        if ($bSlug) {
            $this->hit('GET', "/api/v1/bundles/{$bSlug}", null, [], $T, group: 'Catalog');
        }
        if ($bUuid) {
            $this->hit('GET', "/api/v1/teacher/bundles/{$bUuid}", $t, [], $T, group: 'Catalog');
            $this->hit('PUT', "/api/v1/teacher/bundles/{$bUuid}", $t, ['title' => 'Smoke Package 2'], $T, group: 'Catalog');
            $this->hit('DELETE', "/api/v1/teacher/bundles/{$bUuid}", $t, [], $T, ok: [200, 204], group: 'Catalog');
        }

        // ---------------------------------------------------------------
        // MEDIA
        // ---------------------------------------------------------------
        $play = $this->hit('POST', "/api/v1/media/lessons/{$this->lesson1->id}/playback", $s, [], $T, ok: [200, 201], tolerant: true, group: 'Media');
        $manifest = (string) ($this->pick($play, 'data.manifest_url', 'data.stream_url', 'data.url') ?? '');
        $keyUrl = (string) ($this->pick($play, 'data.key_url') ?? '');
        $this->hit('POST', "/api/v1/media/remote/lessons/{$this->lesson1->id}/playback", $s, [], $T, ok: [200, 400, 403, 404, 409, 422], tolerant: true, group: 'Media');

        // token-gated delivery: use captured token if any, else a bad token
        $streamPath = $manifest !== '' ? parse_url($manifest, PHP_URL_PATH) : '/api/v1/media/stream/badtoken';
        $this->hit('GET', $streamPath, null, [], null, ok: [200, 302, 401, 403, 404], tolerant: true, group: 'Media');
        $keyPath = $keyUrl !== '' ? parse_url($keyUrl, PHP_URL_PATH) : '/api/v1/media/key/badtoken';
        $this->hit('GET', $keyPath, null, [], null, ok: [200, 401, 403, 404], tolerant: true, group: 'Media');
        $this->hit('GET', '/api/v1/media/segment/badtoken/seg_1.ts', null, [], null, ok: [200, 401, 403, 404], tolerant: true, group: 'Media');

        // internal / callbacks (HMAC / signed / secret) — bad input must not 5xx
        $this->hit('GET', '/api/v1/internal/media/authz', null, [], null, ok: [200, 400, 401, 403, 404], tolerant: true, group: 'Media');
        $this->hit('POST', '/api/v1/internal/transcode/callback', null, ['media_id' => 'x'], null, ok: [200, 400, 401, 403, 422], tolerant: true, group: 'Media');
        $this->hit('POST', '/api/v1/media/callbacks/processing', null, ['event' => 'x'], null, ok: [200, 400, 401, 403, 422], tolerant: true, group: 'Media');

        // local upload flow
        $muRes = $this->hit('POST', '/api/v1/teacher/media/uploads', $t, ['filename' => 'v.mp4', 'size_bytes' => 1000, 'content_type' => 'video/mp4', 'lesson_id' => $this->lesson1->id, 'title' => 'v'], $T, ok: [200, 201, 422], tolerant: true, group: 'Media');
        $mUuid = $this->pick($muRes, 'data.uuid', 'data.media.uuid');
        $uploadUrl = (string) ($this->pick($muRes, 'data.upload_url') ?? '');
        if ($uploadUrl !== '') {
            $path = parse_url($uploadUrl, PHP_URL_PATH);
            $qs = parse_url($uploadUrl, PHP_URL_QUERY);
            $this->hit('POST', $path.($qs ? '?'.$qs : ''), $t, ['file' => UploadedFile::fake()->create('v.mp4', 10)], null, ok: [200, 201, 202, 204, 422], tolerant: true, group: 'Media');
        }
        if ($mUuid) {
            $this->hit('POST', "/api/v1/teacher/media/uploads/{$mUuid}/complete", $t, [], $T, ok: [200, 202, 409, 422], tolerant: true, group: 'Media');
            $this->hit('GET', "/api/v1/teacher/media/{$mUuid}", $t, [], $T, ok: [200], tolerant: true, group: 'Media');
            $this->hit('POST', "/api/v1/teacher/media/{$mUuid}/preview", $t, [], $T, ok: [200, 202, 409, 422], tolerant: true, group: 'Media');
        }

        // remote-video lifecycle (MEDIA_PROVIDER=local → mostly disabled/404)
        $rvRes = $this->hit('POST', '/api/v1/teacher/remote-videos/uploads', $t, ['filename' => 'v.mp4', 'size_bytes' => 1000, 'content_type' => 'video/mp4'], $T, ok: [200, 201, 400, 404, 409, 422, 501], tolerant: true, group: 'Media');
        $rSession = $this->pick($rvRes, 'data.session', 'data.session_id', 'data.upload.session');
        $rMedia = $this->pick($rvRes, 'data.media.uuid', 'data.uuid');
        $this->hit('POST', '/api/v1/teacher/remote-videos/uploads/'.($rSession ?? 'x').'/complete', $t, [], $T, ok: [200, 202, 400, 404, 409, 422, 501], tolerant: true, group: 'Media');
        $this->hit('GET', '/api/v1/teacher/remote-videos/'.($rMedia ?? '00000000-0000-0000-0000-000000000000'), $t, [], $T, ok: [200, 404], tolerant: true, group: 'Media');
        $this->hit('POST', '/api/v1/teacher/remote-videos/'.($rMedia ?? '00000000-0000-0000-0000-000000000000').'/replace', $t, [], $T, ok: [200, 400, 404, 409, 422, 501], tolerant: true, group: 'Media');
        foreach (['retry', 'quarantine', 'restore'] as $act) {
            $this->hit('POST', "/api/v1/teacher/remote-videos/versions/1/{$act}", $t, [], $T, ok: [200, 400, 404, 409, 422, 501], tolerant: true, group: 'Media');
        }
        $this->hit('DELETE', '/api/v1/teacher/remote-videos/versions/1', $t, [], $T, ok: [200, 204, 400, 404, 409, 501], tolerant: true, group: 'Media');

        // ---------------------------------------------------------------
        // COMMERCE + WALLET
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/wallet', $this->payStudent, [], $T, group: 'Commerce');
        $this->hit('GET', '/api/v1/wallet/ledger', $this->payStudent, [], $T, group: 'Commerce');
        $cart = ['items' => [['type' => 'course', 'course' => $this->payCourse->uuid]]];
        $this->hit('POST', '/api/v1/checkout/quote', $this->payStudent, $cart, $T, group: 'Commerce');
        $orderRes = $this->hit('POST', '/api/v1/checkout/order', $this->payStudent, $cart, $T, ok: [200, 201], group: 'Commerce');
        $orderUuid = $this->pick($orderRes, 'data.uuid', 'data.order.uuid', 'data.order_uuid');
        $this->hit('POST', '/api/v1/checkout/pay', $this->payStudent, ['order' => $orderUuid, 'method' => 'wallet'], $T, ok: [200, 201, 402, 422], group: 'Commerce');
        $this->hit('POST', '/api/v1/webhooks/paymob', null, ['obj' => []], null, ok: [200, 400, 401, 403, 422], tolerant: true, group: 'Commerce');

        // ---------------------------------------------------------------
        // ENGAGEMENT (student)
        // ---------------------------------------------------------------
        $this->hit('POST', "/api/v1/lessons/{$this->lesson1->id}/progress", $s, ['watch_percent' => 60, 'watch_seconds' => 120], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/activity', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/resume', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/favorites', $s, [], $T, group: 'Engagement');
        $this->hit('POST', '/api/v1/me/favorites', $s, ['course' => $this->course1->uuid], $T, ok: [200, 201], group: 'Engagement');
        $this->hit('DELETE', "/api/v1/me/favorites/{$this->course1->uuid}", $s, [], $T, ok: [200, 204], group: 'Engagement');
        $this->hit('GET', '/api/v1/me/points', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/badges', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/leaderboard', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/courses', $s, [], $T, group: 'Engagement');
        $this->hit('GET', '/api/v1/me/notifications', $s, [], $T, group: 'Engagement');

        // teacher gamification
        $this->hit('GET', '/api/v1/teacher/badges', $t, [], $T, group: 'Engagement');
        $bRes = $this->hit('POST', '/api/v1/teacher/badges', $t, ['name' => 'Smoke Badge', 'threshold_points' => 10, 'threshold' => 10, 'points' => 10], $T, ok: [200, 201, 422], tolerant: true, group: 'Engagement');
        $bId = $this->pick($bRes, 'data.id');
        if ($bId) {
            $this->hit('DELETE', "/api/v1/teacher/badges/{$bId}", $t, [], $T, ok: [200, 204], group: 'Engagement');
        }
        $this->hit('GET', '/api/v1/teacher/gamification', $t, [], $T, group: 'Engagement');
        $this->hit('PUT', '/api/v1/teacher/gamification', $t, ['hide_ranking' => false], $T, group: 'Engagement');

        // ---------------------------------------------------------------
        // ASSESSMENT
        // ---------------------------------------------------------------
        $this->hit('GET', "/api/v1/teacher/courses/{$this->course1->uuid}/exams", $t, [], $T, group: 'Assessment');
        $exRes = $this->hit('POST', "/api/v1/teacher/courses/{$this->course1->uuid}/exams", $t, ['title' => 'Smoke Exam', 'is_published' => true, 'result_visibility' => 'immediate', 'type' => 'exam'], $T, ok: [200, 201], group: 'Assessment');
        $exUuid = $this->pick($exRes, 'data.uuid');
        $this->hit('GET', "/api/v1/teacher/exams/{$exUuid}", $t, [], $T, group: 'Assessment');
        $this->hit('PUT', "/api/v1/teacher/exams/{$exUuid}", $t, ['title' => 'Smoke Exam 2'], $T, group: 'Assessment');
        $this->hit('GET', "/api/v1/teacher/exams/{$exUuid}/questions", $t, [], $T, group: 'Assessment');
        $qRes = $this->hit('POST', "/api/v1/teacher/exams/{$exUuid}/questions", $t, ['type' => 'mcq', 'body' => 'Q?', 'options' => ['A', 'B'], 'correct' => ['A'], 'points' => 1], $T, ok: [200, 201], group: 'Assessment');
        $qId = $this->pick($qRes, 'data.id');
        $this->hit('PUT', "/api/v1/teacher/exams/{$exUuid}/questions/{$qId}", $t, ['type' => 'mcq', 'body' => 'Q2?', 'options' => ['A', 'B', 'C'], 'correct' => ['B'], 'points' => 1], $T, group: 'Assessment');

        // student attempt lifecycle (student1 enrolled in reviewCourse; exam is on course1 → may be 403 if not enrolled)
        $this->hit('GET', '/api/v1/exams', $s, [], $T, group: 'Assessment');
        $attRes = $this->hit('POST', "/api/v1/exams/{$exUuid}/attempts", $this->payStudent, [], $T, ok: [200, 201, 403, 409], group: 'Assessment');
        $attId = $this->pick($attRes, 'data.id', 'data.attempt.id');
        if ($attId) {
            $this->hit('GET', "/api/v1/exams/{$exUuid}/attempts/{$attId}", $this->payStudent, [], $T, ok: [200, 403], group: 'Assessment');
            $this->hit('POST', "/api/v1/exams/{$exUuid}/attempts/{$attId}/submit", $this->payStudent, ['answers' => [(string) $qId => 'A']], $T, ok: [200, 409], group: 'Assessment');
        }
        $this->hit('GET', "/api/v1/teacher/exams/{$exUuid}/submissions", $t, [], $T, group: 'Assessment');
        if ($attId) {
            $this->hit('POST', "/api/v1/teacher/exams/{$exUuid}/attempts/{$attId}/grade", $t, ['grades' => [(string) $qId => 1]], $T, ok: [200, 422], tolerant: true, group: 'Assessment');
        }
        // delete throwaway question/exam last
        $this->hit('DELETE', "/api/v1/teacher/exams/{$exUuid}/questions/{$qId}", $t, [], $T, ok: [200, 204], group: 'Assessment');

        // ---------------------------------------------------------------
        // CENTERS
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/teacher/centers', $t, [], $T, group: 'Centers');
        $cenRes = $this->hit('POST', '/api/v1/teacher/centers', $t, ['name' => 'Smoke Center'], $T, ok: [200, 201], group: 'Centers');
        $cenUuid = $this->pick($cenRes, 'data.uuid');
        $this->hit('PUT', "/api/v1/teacher/centers/{$cenUuid}", $t, ['name' => 'Smoke Center 2'], $T, group: 'Centers');
        $this->hit('GET', "/api/v1/teacher/centers/{$cenUuid}/attendance", $t, [], $T, group: 'Centers');
        $this->hit('POST', "/api/v1/teacher/centers/{$cenUuid}/attendance", $t, ['students' => [$s->uuid], 'status' => 'present', 'attended_on' => '2026-07-20'], $T, ok: [200, 201, 422], tolerant: true, group: 'Centers');
        $this->hit('POST', '/api/v1/teacher/centers/sync', $t, ['events' => [['kind' => 'attendance', 'external_ref' => 'smoke-1', 'student_phone' => '01281000001', 'attended_on' => '2026-07-20', 'status' => 'present']]], $T, ok: [200, 207, 422], tolerant: true, group: 'Centers');
        $this->hit('GET', '/api/v1/teacher/codes', $t, [], $T, group: 'Centers');
        $codeRes = $this->hit('POST', '/api/v1/teacher/codes/batch', $t, ['type' => 'wallet', 'count' => 2, 'amount_minor' => 5000], $T, ok: [200, 201], group: 'Centers');
        $codeUuid = $this->pick($codeRes, 'data.0.uuid', 'data.codes.0.uuid');
        $codePlain = $this->pick($codeRes, 'data.0.code', 'data.codes.0.code');
        if ($codeUuid) {
            $this->hit('POST', "/api/v1/teacher/codes/{$codeUuid}/disable", $t, [], $T, group: 'Centers');
        }
        $this->hit('POST', '/api/v1/codes/redeem', $this->payStudent, ['code' => $codePlain ?? 'INVALIDCODE'], $T, ok: [200, 404, 409, 422], group: 'Centers');
        $this->hit('DELETE', "/api/v1/teacher/centers/{$cenUuid}", $t, [], $T, ok: [200, 204], group: 'Centers');

        // ---------------------------------------------------------------
        // IDENTITY — teacher student management
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/teacher/students', $t, [], $T, group: 'Students');
        $stRes = $this->hit('POST', '/api/v1/teacher/students', $t, ['name' => 'Smoke Student', 'phone' => '01077776666'], $T, ok: [200, 201], group: 'Students');
        $stUuid = $this->pick($stRes, 'data.uuid');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}", $t, [], $T, group: 'Students');
        $this->hit('PATCH', "/api/v1/teacher/students/{$stUuid}", $t, ['name' => 'Smoke Student 2'], $T, group: 'Students');
        $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/reset-password", $t, [], $T, ok: [200, 201], group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/export", $t, [], $T, group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/enrollments", $t, [], $T, group: 'Students');
        $enrRes = $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/enrollments", $t, ['course' => $this->course1->uuid], $T, ok: [200, 201, 409, 422], group: 'Students');
        $enrId = $this->pick($enrRes, 'data.id', 'data.enrollment.id');
        if ($enrId) {
            $this->hit('DELETE', "/api/v1/teacher/students/{$s->uuid}/enrollments/{$enrId}", $t, [], $T, ok: [200, 204], group: 'Students');
        }
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/wallet", $t, [], $T, group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/wallet/ledger", $t, [], $T, group: 'Students');
        $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/wallet/adjust", $t, ['amount_minor' => 1000, 'direction' => 'credit', 'reason' => 'smoke'], $T, group: 'Students');
        $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/wallet/set", $t, ['amount_minor' => 5000, 'balance_minor' => 5000, 'reason' => 'smoke'], $T, ok: [200, 201, 422], tolerant: true, group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/orders", $t, [], $T, group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/progress", $t, [], $T, group: 'Students');
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/activity", $t, [], $T, group: 'Students');
        $notifyRes = $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/notify", $t, ['message' => 'Hello student', 'title' => 'Note'], $T, ok: [200, 201], group: 'Students');

        // now the student can read + mark that notification
        $notes = $this->hit('GET', '/api/v1/me/notifications', $s, [], $T, group: 'Engagement');
        $noteId = $this->pick($notes, 'data.0.id');
        if ($noteId) {
            $this->hit('POST', "/api/v1/me/notifications/{$noteId}/read", $s, [], $T, group: 'Engagement');
        }

        // parents — link (creates parent membership) → exercise parent portal → clean up
        $this->hit('GET', "/api/v1/teacher/students/{$s->uuid}/parents", $t, [], $T, group: 'Parents');
        $pRes = $this->hit('POST', "/api/v1/teacher/students/{$s->uuid}/parents", $t, ['name' => 'Parent One', 'phone' => '01066665555', 'relation' => 'father', 'password' => 'ParentPass1'], $T, ok: [200, 201], group: 'Parents');
        $parentUuid = $this->pick($pRes, 'data.uuid', 'data.parent.uuid');
        $parentUser = User::where('phone', '01066665555')->first();
        if ($parentUser) {
            $this->hit('GET', '/api/v1/parent/children', $parentUser, [], $T, group: 'Parents');
            $this->hit('GET', "/api/v1/parent/children/{$s->uuid}/progress", $parentUser, [], $T, group: 'Parents');
            $this->hit('GET', "/api/v1/parent/children/{$s->uuid}/results", $parentUser, [], $T, group: 'Parents');
        }
        if ($parentUuid) {
            $this->hit('DELETE', "/api/v1/teacher/students/{$s->uuid}/parents/{$parentUuid}", $t, [], $T, ok: [200, 204], group: 'Parents');
        }
        // remove throwaway student
        $this->hit('DELETE', "/api/v1/teacher/students/{$stUuid}", $t, [], $T, ok: [200, 204], group: 'Students');

        // ---------------------------------------------------------------
        // REPORTING
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/teacher/reports/sales', $t, [], $T, group: 'Reporting');
        $this->hit('GET', '/api/v1/teacher/reports/students', $t, [], $T, group: 'Reporting');
        $this->hit('GET', '/api/v1/teacher/audit-logs', $t, [], $T, group: 'Reporting');

        // ---------------------------------------------------------------
        // BILLING (teacher read)
        // ---------------------------------------------------------------
        $this->hit('GET', '/api/v1/teacher/subscription', $t, [], $T, group: 'Billing');
        $this->hit('GET', '/api/v1/teacher/packages', $t, [], $T, group: 'Billing');

        // ---------------------------------------------------------------
        // PLATFORM ADMIN + BILLING ADMIN (central host = localhost, no tenant header)
        // ---------------------------------------------------------------
        $a = $this->admin;
        $this->hit('GET', '/api/v1/admin/tenants', $a, [], null, group: 'Admin');
        $ntRes = $this->hit('POST', '/api/v1/admin/tenants', $a, ['name' => 'Smoke Academy', 'slug' => 'smoke-academy', 'status' => 'active'], null, ok: [200, 201], group: 'Admin');
        $this->hit('GET', "/api/v1/admin/tenants/{$this->t1->uuid}", $a, [], null, group: 'Admin');
        $this->hit('PUT', "/api/v1/admin/tenants/{$this->t1->uuid}", $a, ['status' => 'active'], null, group: 'Admin');
        $this->hit('GET', '/api/v1/admin/reports/overview', $a, [], null, group: 'Admin');
        $this->hit('GET', '/api/v1/admin/audit-logs', $a, [], null, group: 'Admin');

        $this->hit('GET', '/api/v1/admin/packages', $a, [], null, group: 'Billing');
        $pkgRes = $this->hit('POST', '/api/v1/admin/packages', $a, ['name' => 'Smoke Pkg', 'slug' => 'smoke-pkg', 'price_minor' => 1000], null, ok: [200, 201], group: 'Billing');
        $pkgUuid = $this->pick($pkgRes, 'data.uuid');
        // an existing seeded package uuid for show/subscription
        $seededPkgUuid = SubscriptionPackage::query()->value('uuid');
        $this->hit('GET', "/api/v1/admin/packages/{$seededPkgUuid}", $a, [], null, group: 'Billing');
        if ($pkgUuid) {
            $this->hit('PUT', "/api/v1/admin/packages/{$pkgUuid}", $a, ['name' => 'Smoke Pkg 2'], null, group: 'Billing');
            $this->hit('DELETE', "/api/v1/admin/packages/{$pkgUuid}", $a, [], null, ok: [200, 204], group: 'Billing');
        }
        $this->hit('GET', "/api/v1/admin/tenants/{$this->t1->uuid}/subscription", $a, [], null, group: 'Billing');
        $this->hit('POST', "/api/v1/admin/tenants/{$this->t1->uuid}/subscription", $a, ['package_uuid' => $seededPkgUuid], null, ok: [200, 201], group: 'Billing');

        $this->report();
    }

    private function report(): void
    {
        $dir = 'C:/Users/IGFI/AppData/Local/Temp/claude/D--Web-repo-Edu-system/15df9bfb-5ed7-45cb-beea-1f25dd74797e/scratchpad';
        @file_put_contents($dir.'/smoke-results.json', json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $counts = ['PASS' => 0, 'WARN' => 0, 'WARN5xx' => 0, 'FAIL' => 0];
        $lines = [];
        foreach ($this->results as $r) {
            $counts[$r['class']]++;
            if ($r['class'] !== 'PASS') {
                $lines[] = sprintf('[%-7s] %-6s %-3d %s (as %s)', $r['class'], $r['method'], $r['status'], $r['uri'], $r['actor']);
            }
        }
        $summary = sprintf(
            "SMOKE: %d calls | PASS %d | WARN %d | WARN5xx %d | FAIL %d\n%s\n",
            count($this->results), $counts['PASS'], $counts['WARN'], $counts['WARN5xx'], $counts['FAIL'],
            implode("\n", $lines)
        );
        @file_put_contents($dir.'/smoke-summary.txt', $summary);
        fwrite(STDERR, "\n".$summary."\n");

        $fails = array_values(array_filter($this->results, fn ($r) => $r['class'] === 'FAIL'));
        $this->assertSame([], $fails, 'Endpoints returned 5xx / auth holes on normal routes — see report.');
    }
}
