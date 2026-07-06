<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaybackAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function lessonWithVideo(bool $freePreview = false, bool $freeCourse = false): Lesson
    {
        $course = new Course([
            'title' => 'C', 'visibility' => ContentVisibility::Visible->value,
            'price_minor' => 10000, 'is_free' => $freeCourse,
        ]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L', 'is_free_preview' => $freePreview]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value]);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        $lesson->update(['video_asset_id' => $asset->id]);

        return $lesson->fresh();
    }

    public function test_enrolled_student_gets_playback_token_and_key(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'manifest_url', 'key_url', 'expires_at']]);

        $token = $res->json('data.token');

        // Key endpoint releases the AES key for a valid token.
        $this->getJson("/api/v1/media/key/{$token}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['key']]);

        // nginx authz accepts the token.
        $this->getJson("/api/v1/internal/media/authz?token={$token}")->assertNoContent();
    }

    public function test_enrolled_student_streams_the_video_via_manifest_url(): void
    {
        Storage::fake('public');
        $student = $this->student();
        $lesson = $this->lessonWithVideo();

        $asset = MediaAsset::withoutGlobalScopes()->find($lesson->video_asset_id);
        Storage::disk('public')->put($path = "media/source/{$asset->uuid}.mp4", 'FAKE-MP4-BYTES');
        $asset->update(['source_key' => $path]);

        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);
        Sanctum::actingAs($student);

        $manifest = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()->json('data.manifest_url');

        $this->assertStringContainsString('/api/v1/media/stream/', $manifest);

        // The manifest URL (token in the path) actually streams the video.
        $stream = $this->get($manifest)->assertOk();
        $this->assertStringContainsString('video', strtolower((string) $stream->headers->get('Content-Type')));
    }

    public function test_unenrolled_student_is_denied_playback(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo();

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(403);
    }

    public function test_free_preview_lesson_is_playable_without_enrollment(): void
    {
        $student = $this->student();
        $lesson = $this->lessonWithVideo(freePreview: true);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk();
    }

    public function test_invalid_token_key_and_authz_are_denied(): void
    {
        $this->getJson('/api/v1/media/key/not-a-real-token')->assertStatus(403);
        $this->getJson('/api/v1/internal/media/authz?token=nope')->assertStatus(403);
    }

    public function test_cross_tenant_lesson_playback_is_404(): void
    {
        // Lesson belongs to `demo`; a student of another tenant requests it.
        $lesson = $this->lessonWithVideo();
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $intruder = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $other->id, 'user_id' => $intruder->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($intruder);
        $this->withHeader('X-Tenant', 'other')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(404); // route-model binding scoped to `other` → not found
    }
}
