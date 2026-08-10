<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Events\LessonCompleted;
use App\Modules\Catalog\Services\SequentialUnlockService;

/**
 * The sequential-unlock event worker (B14 / VD R5). When a lesson is completed it
 * opens the NEXT lesson's availability window — and ONLY the next one (VD-D3:
 * completion advances by one step; expiry never does). Sync so the newly opened
 * window is visible the moment the student reports completion; the work is a
 * couple of scoped queries + an idempotent window open, so no queue is warranted.
 */
class OpenNextLessonWindow
{
    public function __construct(
        private readonly SequentialUnlockService $sequential,
    ) {}

    public function handle(LessonCompleted $event): void
    {
        $this->sequential->advanceAfter($event->tenantId, $event->userId, $event->lessonId);
    }
}
