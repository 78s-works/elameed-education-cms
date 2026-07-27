<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
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

/**
 * Regression for B2: playback / preview of an asset that is marked "ready" but has
 * no transcodable source (or no FFmpeg) must return a clean mapped envelope
 * (409/503), never a raw 500 with a leaked stack trace.
 */
class MediaNotReadyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function membership(User $user, TenantUserRole $role): void
    {
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
    }

    /** A ready HLS lesson whose source_key file is intentionally absent on disk. */
    private function lessonWithMissingSource(bool $freePreview = true): Lesson
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L', 'is_free_preview' => $freePreview]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        // status = ready, source_key set — but the file is never written to the fake disk.
        $asset = new MediaAsset(['lesson_id' => $lesson->id, 'type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/missing.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        $lesson->update(['video_asset_id' => $asset->id]);

        return $lesson->fresh();
    }

    public function test_student_playback_of_unready_source_returns_409_not_500(): void
    {
        $student = User::factory()->create();
        $this->membership($student, TenantUserRole::Student);
        $lesson = $this->lessonWithMissingSource(freePreview: true);

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback");

        $res->assertStatus(409)
            ->assertJsonPath('error.code', 'media_not_ready')
            ->assertJsonStructure(['error' => ['code', 'message']]);
        // No internal leak.
        $this->assertStringNotContainsStringIgnoringCase('Storage', (string) $res->getContent());
        $this->assertStringNotContainsStringIgnoringCase('.mp4', (string) $res->getContent());
    }

    public function test_playback_without_ffmpeg_backend_returns_503(): void
    {
        config(['media.ffmpeg_bin' => '']); // no transcode backend configured

        $student = User::factory()->create();
        $this->membership($student, TenantUserRole::Student);
        $lesson = $this->lessonWithMissingSource(freePreview: true);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'media_processing_unavailable');
    }

    public function test_teacher_preview_of_unready_source_returns_409_not_500(): void
    {
        $teacher = User::factory()->create();
        $this->membership($teacher, TenantUserRole::Teacher);
        $lesson = $this->lessonWithMissingSource(freePreview: false);
        $asset = MediaAsset::withoutGlobalScopes()->find($lesson->video_asset_id);

        Sanctum::actingAs($teacher);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/teacher/media/{$asset->uuid}/preview")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'media_not_ready');
    }
}
