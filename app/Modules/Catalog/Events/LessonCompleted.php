<?php

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student finished a lesson (watched its video to completion — the
 * `lesson_progress.completed_at` signal, VD-D3 "completed"). The sequential
 * unlock engine reacts to this to open the NEXT lesson's window in every package
 * the student bought that contains this lesson (B14 / VD R5). Carries plain scalar
 * ids so the listener can run scope-free (webhook/queue-safe).
 */
class LessonCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $lessonId,
    ) {}
}
