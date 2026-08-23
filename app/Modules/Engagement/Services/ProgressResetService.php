<?php

namespace App\Modules\Engagement\Services;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Media\Models\PlaybackSession;

/**
 * Wipes a student's progress on a single lesson so a RE-PURCHASE (after a teacher
 * revoked their access) starts them fresh — they redo the lesson video, the
 * homework and the quiz (STUDENT DASHBOARD.docx). It deletes:
 *
 *   - lesson_progress   — video watch state (percent/seconds/completion)
 *   - exam_attempts      — every attempt on the lesson's exams (homework + quiz)
 *   - playback_sessions  — issued video-playback tokens
 *
 * Scoped explicitly by tenant + user (+ lesson) and global-scope-free, so it is
 * safe from a payment-webhook context where no tenant is resolved from the host.
 */
class ProgressResetService
{
    public function resetLesson(int $tenantId, int $userId, int $lessonId): void
    {
        LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->delete();

        // Every exam attached to the lesson (its homework + quiz). withoutGlobalScopes
        // also drops the soft-delete scope, so attempts on a retired exam clear too.
        $examIds = Exam::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lessonId)
            ->pluck('id');

        if ($examIds->isNotEmpty()) {
            ExamAttempt::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->whereIn('exam_id', $examIds->all())
                ->delete();
        }

        PlaybackSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->delete();
    }
}
