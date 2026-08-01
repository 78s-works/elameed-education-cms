<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\ContentDependency;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C2b — a locked lesson section must not be reachable by hitting the exam-start or
 * playback endpoint directly. The `locked` flag on the sections listing only hides
 * the reference; enforcement has to live in the access gates themselves
 * (`ContentUnlockService::isSectionLocked`, wired into `AttemptController::start`
 * and `PlaybackService`). These tests assert both directions: blocked while the
 * hosting section's prerequisite is unmet, allowed once it's satisfied.
 */
class SectionLockEnforcementTest extends TestCase
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

    private function student(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function course(): Course
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        return $course;
    }

    private function lesson(Course $course): Lesson
    {
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U', 'sort_order' => 0]);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L', 'sort_order' => 0]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function exam(int $courseId, string $type = 'exam'): Exam
    {
        $exam = new Exam(['course_id' => $courseId, 'title' => 'X', 'type' => $type, 'pass_percent' => 50, 'is_published' => true]);
        $exam->tenant_id = $this->tenant->id;
        $exam->save();

        return $exam;
    }

    private function section(Lesson $lesson, array $attrs): LessonSection
    {
        $section = new LessonSection(['lesson_id' => $lesson->id] + $attrs);
        $section->tenant_id = $this->tenant->id;
        $section->save();

        return $section;
    }

    private function dependency(LessonSection $section, LessonSection $dependsOn, string $trigger = 'submitted'): void
    {
        $dep = new ContentDependency([
            'section_id' => $section->id, 'depends_on_section_id' => $dependsOn->id,
            'trigger' => $trigger, 'enforcement' => 'mandatory',
        ]);
        $dep->tenant_id = $this->tenant->id;
        $dep->save();
    }

    private function enroll(User $student, Course $course): void
    {
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);
    }

    /** Record a submitted attempt so a `submitted`-trigger prerequisite is satisfied. */
    private function submitAttempt(Exam $exam, User $student): void
    {
        $a = new ExamAttempt([
            'exam_id' => $exam->id, 'user_id' => $student->id,
            'attempt_number' => 1, 'status' => AttemptStatus::Submitted->value,
        ]);
        $a->tenant_id = $this->tenant->id;
        $a->started_at = now();
        $a->submitted_at = now();
        $a->save();
    }

    // ---- exam-start respects the hosting section's lock --------------------------

    public function test_section_bound_exam_cannot_be_started_until_its_section_unlocks(): void
    {
        $student = $this->student();
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        // Entry quiz (prerequisite) and a homework whose section depends on it.
        $entryExam = $this->exam($course->id, 'exam');
        $entry = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $entryExam->id, 'sort_order' => 0]);

        $homeworkExam = $this->exam($course->id, 'assignment');
        $homework = $this->section($lesson, ['type' => 'assignment', 'assignment_kind' => 'onsite', 'exam_id' => $homeworkExam->id, 'sort_order' => 1]);
        $this->dependency($homework, $entry, 'submitted');

        Sanctum::actingAs($student);

        // The homework's section is locked, so its exam can't be started directly…
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$homeworkExam->uuid}/attempts")
            ->assertStatus(403);

        // …but the entry quiz (no unmet dependency) starts fine.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$entryExam->uuid}/attempts")
            ->assertOk();

        // Satisfy the prerequisite → the homework exam becomes startable.
        $this->submitAttempt($entryExam, $student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$homeworkExam->uuid}/attempts")
            ->assertOk();
    }

    /** A course/unit exam that no section hosts is unaffected by the new gate. */
    public function test_non_section_exam_is_not_gated_by_section_lock(): void
    {
        $student = $this->student();
        $course = $this->course();
        $this->enroll($student, $course);
        $exam = $this->exam($course->id, 'exam');

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/exams/{$exam->uuid}/attempts")
            ->assertOk();
    }

    // ---- playback respects a lock on the section that hosts the lesson video -----

    public function test_locked_lecture_video_section_blocks_playback(): void
    {
        $student = $this->student();
        $course = $this->course();
        $lesson = $this->lesson($course);
        $this->enroll($student, $course);

        // The lesson's protected video.
        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/x.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        Storage::disk('local')->put('media/source/x.mp4', 'SOURCE');
        $lesson->update(['video_asset_id' => $asset->id]);

        // A ready rendition so an authorized play would succeed (isolates the gate).
        $this->seedRendition($asset, $student->id);

        // Entry quiz + a lecture_video section that serves the lesson video and
        // depends on the quiz being submitted.
        $entryExam = $this->exam($course->id, 'exam');
        $entry = $this->section($lesson, ['type' => 'quiz', 'exam_id' => $entryExam->id, 'sort_order' => 0]);
        $video = $this->section($lesson, ['type' => 'lecture_video', 'media_asset_id' => $asset->id, 'sort_order' => 1]);
        $this->dependency($video, $entry, 'submitted');

        Sanctum::actingAs($student);

        // Blocked: the video's section is locked by the unmet quiz prerequisite.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(403);

        // Satisfy the quiz → playback authorizes and issues a token.
        $this->submitAttempt($entryExam, $student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'manifest_url', 'key_url', 'expires_at']]);
    }

    /** Seed a ready encrypted rendition for (asset, user) so issue() skips FFmpeg. */
    private function seedRendition(MediaAsset $asset, int $userId): void
    {
        $dir = "media/hls/{$asset->uuid}/{$userId}";
        Storage::disk('local')->put("{$dir}/index.m3u8", "#EXTM3U\n#EXT-X-ENDLIST\n");
        Storage::disk('local')->put("{$dir}/seg_000.ts", 'ENCRYPTED');

        $r = new MediaRendition;
        $r->tenant_id = $this->tenant->id;
        $r->media_asset_id = $asset->id;
        $r->user_id = $userId;
        $r->fill(['status' => 'ready', 'hls_dir' => $dir, 'enc_key' => base64_encode(random_bytes(16)), 'iv' => str_repeat('0', 32), 'segment_count' => 1]);
        $r->save();
    }
}
