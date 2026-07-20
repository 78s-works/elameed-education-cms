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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dual-source lesson video (docs/design/lesson-video-sources.md): a lesson may
 * hold both a protected upload and a YouTube link, with a teacher-controlled
 * toggle for which one students see. Students only ever receive the ACTIVE source.
 */
class YoutubeLessonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private const URL = 'https://youtu.be/dQw4w9WgXcQ';

    private const VIDEO_ID = 'dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
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

    /** A visible course → unit → lesson. Attributes let a test set the video source. */
    private function makeLesson(array $lessonAttrs = [], bool $freeCourse = false): Lesson
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

        $lesson = new Lesson(array_merge(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L'], $lessonAttrs));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function readyUploadAsset(): MediaAsset
    {
        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/x.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();

        return $asset;
    }

    // ---- Authoring ---------------------------------------------------------

    public function test_teacher_sets_youtube_link_and_toggles_it_active(): void
    {
        Sanctum::actingAs($this->teacher());
        $lesson = $this->makeLesson();
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->putJson("/api/v1/teacher/units/{$lesson->unit_id}/lessons/{$lesson->id}", [
            'title' => 'L',
            'youtube_url' => self::URL,
            'active_video_source' => 'youtube',
        ])->assertOk()
            ->assertJsonPath('data.active_video_source', 'youtube')
            ->assertJsonPath('data.youtube_url', self::URL)
            ->assertJsonPath('data.has_video', true);

        $this->assertSame('youtube', $lesson->fresh()->active_video_source->value);
    }

    public function test_activating_youtube_without_a_link_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $lesson = $this->makeLesson();

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->putJson("/api/v1/teacher/units/{$lesson->unit_id}/lessons/{$lesson->id}", [
                'title' => 'L',
                'active_video_source' => 'youtube',
            ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_invalid_youtube_url_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $lesson = $this->makeLesson();

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->putJson("/api/v1/teacher/units/{$lesson->unit_id}/lessons/{$lesson->id}", [
                'title' => 'L',
                'youtube_url' => 'https://vimeo.com/12345',
            ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    // ---- Playback ----------------------------------------------------------

    public function test_enrolled_student_gets_youtube_embed_payload(): void
    {
        $student = $this->student();
        $lesson = $this->makeLesson(['active_video_source' => 'youtube', 'youtube_url' => self::URL]);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonPath('data.source', 'youtube')
            ->assertJsonPath('data.video_id', self::VIDEO_ID)
            ->assertJsonPath('data.embed_url', 'https://www.youtube-nocookie.com/embed/'.self::VIDEO_ID);
    }

    public function test_unenrolled_student_is_denied_youtube_playback(): void
    {
        $student = $this->student();
        $lesson = $this->makeLesson(['active_video_source' => 'youtube', 'youtube_url' => self::URL]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(403);
    }

    public function test_free_preview_youtube_lesson_is_playable_without_enrollment(): void
    {
        $student = $this->student();
        $lesson = $this->makeLesson(['active_video_source' => 'youtube', 'youtube_url' => self::URL, 'is_free_preview' => true]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonPath('data.source', 'youtube');
    }

    /**
     * The toggle hides the inactive source: a lesson with BOTH a ready upload and
     * a YouTube link, active=youtube, serves YouTube — never the HLS token.
     */
    public function test_active_youtube_wins_over_a_stored_upload(): void
    {
        $student = $this->student();
        $asset = $this->readyUploadAsset();
        $lesson = $this->makeLesson([
            'active_video_source' => 'youtube',
            'youtube_url' => self::URL,
            'video_asset_id' => $asset->id,
            'is_free_preview' => true,
        ]);

        Sanctum::actingAs($student);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonPath('data.source', 'youtube');

        // No encrypted-HLS token/key leaked when YouTube is active.
        $this->assertArrayNotHasKey('token', $res->json('data'));
        $this->assertArrayNotHasKey('key_url', $res->json('data'));
    }

    // ---- Non-leak ----------------------------------------------------------

    public function test_public_detail_exposes_source_but_not_the_youtube_url(): void
    {
        $lesson = $this->makeLesson(['active_video_source' => 'youtube', 'youtube_url' => self::URL]);
        $slug = $lesson->course->slug;

        $res = $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/courses/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.units.0.lessons.0.has_video', true)
            ->assertJsonPath('data.units.0.lessons.0.active_video_source', 'youtube');

        // The raw YouTube URL is NEVER in the public outline — it's released only
        // through the enrollment-gated playback endpoint.
        $this->assertStringNotContainsString(self::VIDEO_ID, $res->getContent());
    }
}
