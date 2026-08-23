<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * STUDENT DASHBOARD.docx — re-buy after a revoke resets progress. When a teacher
 * revokes a student's lesson access (the enrollment goes `cancelled`) and the
 * student RE-BUYS it, their old progress — lesson-video watch, homework/quiz
 * attempts, playback tokens — is wiped so they redo it. A first-time buy, or a
 * re-grant with no prior revoke, leaves progress alone. Exercised through
 * EnrollmentService::grantLesson, the shared grant path behind both checkout
 * fulfilment and teacher manual grants.
 */
class RebuyProgressResetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->userId = User::factory()->create()->id;
        $year = new AcademicYear(['name' => '2025 / 2026', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();
        $this->year = $year;
    }

    private function service(): EnrollmentService
    {
        return app(EnrollmentService::class);
    }

    private function lesson(string $title): Lesson
    {
        $lesson = new Lesson(['title' => $title, 'access_mode' => 'both', 'availability_days' => null]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year->id;
        $lesson->save();

        return $lesson;
    }

    /** Seed one video-watch row, one exam attempt, one playback token. Returns exam id. */
    private function seedProgress(Lesson $lesson): int
    {
        $exam = new Exam(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'type' => 'lesson_quiz', 'pass_percent' => 50]);
        $exam->tenant_id = $this->tenant->id;
        $exam->academic_year_id = $this->year->id;
        $exam->save();

        $progress = new LessonProgress(['lesson_id' => $lesson->id, 'user_id' => $this->userId, 'watch_percent' => 80]);
        $progress->tenant_id = $this->tenant->id;
        $progress->save();

        $attempt = new ExamAttempt(['exam_id' => $exam->id, 'user_id' => $this->userId, 'attempt_number' => 1, 'status' => 'submitted']);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->save();

        $session = new PlaybackSession(['user_id' => $this->userId, 'lesson_id' => $lesson->id, 'token_hash' => 'tok', 'expires_at' => now()->addHour()]);
        $session->tenant_id = $this->tenant->id;
        $session->save();

        return $exam->id;
    }

    private function cancelledEnrollment(Lesson $lesson): void
    {
        $e = new Enrollment([
            'user_id' => $this->userId,
            'lesson_id' => $lesson->id,
            'source' => EnrollmentSource::Purchase->value,
            'status' => EnrollmentStatus::Cancelled->value,
            'starts_at' => now()->subMonth(),
        ]);
        $e->tenant_id = $this->tenant->id;
        $e->save();
    }

    private function assertProgressCounts(Lesson $lesson, int $examId, int $expected): void
    {
        $this->assertSame($expected, LessonProgress::withoutGlobalScopes()->where('user_id', $this->userId)->where('lesson_id', $lesson->id)->count());
        $this->assertSame($expected, ExamAttempt::withoutGlobalScopes()->where('user_id', $this->userId)->where('exam_id', $examId)->count());
        $this->assertSame($expected, PlaybackSession::withoutGlobalScopes()->where('user_id', $this->userId)->where('lesson_id', $lesson->id)->count());
    }

    public function test_rebuy_after_revoke_wipes_progress(): void
    {
        $lesson = $this->lesson('L1');
        $examId = $this->seedProgress($lesson);
        $this->cancelledEnrollment($lesson);
        $this->assertProgressCounts($lesson, $examId, 1);

        $this->service()->grantLesson($this->tenant->id, $this->userId, $lesson, EnrollmentSource::Purchase);

        // Old progress wiped; a fresh active enrollment now exists.
        $this->assertProgressCounts($lesson, $examId, 0);
        $this->assertSame(1, Enrollment::withoutGlobalScopes()
            ->where('user_id', $this->userId)->where('lesson_id', $lesson->id)
            ->where('status', EnrollmentStatus::Active->value)->count());
    }

    public function test_first_time_buy_keeps_progress(): void
    {
        // No prior cancelled enrollment — the guard must NOT fire, so progress stays.
        $lesson = $this->lesson('L1');
        $examId = $this->seedProgress($lesson);

        $this->service()->grantLesson($this->tenant->id, $this->userId, $lesson, EnrollmentSource::Purchase);

        $this->assertProgressCounts($lesson, $examId, 1);
    }

    public function test_expired_enrollment_alone_does_not_reset(): void
    {
        // A naturally expired window (not a teacher revoke) must not wipe progress.
        $lesson = $this->lesson('L1');
        $examId = $this->seedProgress($lesson);
        $e = new Enrollment([
            'user_id' => $this->userId,
            'lesson_id' => $lesson->id,
            'source' => EnrollmentSource::Purchase->value,
            'status' => EnrollmentStatus::Expired->value,
            'starts_at' => now()->subMonth(),
        ]);
        $e->tenant_id = $this->tenant->id;
        $e->save();

        $this->service()->grantLesson($this->tenant->id, $this->userId, $lesson, EnrollmentSource::Purchase);

        $this->assertProgressCounts($lesson, $examId, 1);
    }
}
