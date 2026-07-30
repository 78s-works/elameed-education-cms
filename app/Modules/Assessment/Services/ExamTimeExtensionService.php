<?php

namespace App\Modules\Assessment\Services;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamTimeExtension;
use App\Modules\Catalog\Enums\ExtensionStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Per-student exam/quiz time extensions (doc 11 R6 + R8). A student asks for more
 * time; staff grant a number of minutes (capped by `exams.max_time_extensions`).
 * The attempt timer adds the granted minutes to the exam's `duration_min` for
 * that student. Access-critical: explicit tenant id, queries withoutGlobalScopes.
 */
class ExamTimeExtensionService
{
    /** Student requests extra time. One pending request at a time; allowance must remain. */
    public function request(int $tenantId, int $userId, Exam $exam, ?int $minutes): ExamTimeExtension
    {
        if ((int) $exam->max_time_extensions <= 0) {
            throw new ConflictHttpException('Time extensions are not allowed for this exam.');
        }

        if ($this->grantedCount($tenantId, $userId, $exam) >= (int) $exam->max_time_extensions) {
            throw new ConflictHttpException('No time-extension requests remain for this exam.');
        }

        $hasPending = ExamTimeExtension::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->where('status', ExtensionStatus::Pending->value)
            ->exists();

        if ($hasPending) {
            throw new ConflictHttpException('A time-extension request is already pending.');
        }

        $row = new ExamTimeExtension([
            'exam_id' => $exam->getKey(),
            'user_id' => $userId,
            'requested_minutes' => $minutes,
            'status' => ExtensionStatus::Pending->value,
            'requested_at' => now(),
        ]);
        $row->tenant_id = $tenantId;
        $row->save();

        return $row;
    }

    /** Staff decide. A grant records `granted_minutes` (falls back to requested). */
    public function decide(int $tenantId, ExamTimeExtension $request, bool $grant, ?int $minutes, int $staffId): ExamTimeExtension
    {
        if ($request->status !== ExtensionStatus::Pending) {
            throw new ConflictHttpException('This request has already been decided.');
        }

        if ($grant) {
            $exam = Exam::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($request->exam_id);

            if ($this->grantedCount($tenantId, (int) $request->user_id, $exam) >= (int) $exam->max_time_extensions) {
                throw new ConflictHttpException('The student has no time-extension allowance left.');
            }

            $request->granted_minutes = $minutes ?? $request->requested_minutes ?? 0;
            $request->status = ExtensionStatus::Granted;
        } else {
            $request->status = ExtensionStatus::Denied;
        }

        $request->decided_at = now();
        $request->decided_by = $staffId;
        $request->save();

        return $request;
    }

    /** Total granted extension minutes for this (student, exam) — added to duration_min. */
    public function grantedMinutes(int $tenantId, int $userId, Exam $exam): int
    {
        return (int) ExamTimeExtension::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->where('status', ExtensionStatus::Granted->value)
            ->sum('granted_minutes');
    }

    /**
     * Effective duration for a student, in minutes, or null when the exam is
     * untimed (adding time to an untimed exam is a no-op).
     */
    public function effectiveDuration(int $tenantId, int $userId, Exam $exam): ?int
    {
        if ($exam->duration_min === null) {
            return null;
        }

        return (int) $exam->duration_min + $this->grantedMinutes($tenantId, $userId, $exam);
    }

    private function grantedCount(int $tenantId, int $userId, Exam $exam): int
    {
        return ExamTimeExtension::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('exam_id', $exam->getKey())
            ->where('user_id', $userId)
            ->where('status', ExtensionStatus::Granted->value)
            ->count();
    }
}
