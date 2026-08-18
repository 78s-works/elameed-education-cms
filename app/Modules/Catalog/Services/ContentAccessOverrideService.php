<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ContentAccessTarget;
use App\Modules\Catalog\Models\ContentAccessOverride;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;

/**
 * Manual staff access overrides — grant/revoke plus the read side the access
 * gates use to short-circuit locked content to unlocked.
 *
 * Coverage is hierarchical: a `unit` override covers every section/lesson under
 * it, a `lesson` override covers that lesson + its sections, a `section` override
 * covers just that section. Access-critical, so tenant id is explicit and queries
 * run withoutGlobalScopes (mirrors EnrollmentService / ContentUnlockService).
 */
class ContentAccessOverrideService
{
    /**
     * Grant (or re-activate) an active override for one student on one target.
     * Idempotent: an already-active override for the same target is returned as-is
     * (note refreshed); a previously revoked one is re-activated.
     */
    public function grant(int $tenantId, int $userId, ContentAccessTarget $target, int $targetId, ?int $grantedBy, ?string $note = null): ContentAccessOverride
    {
        $column = $target->column();

        $override = ContentAccessOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where($column, $targetId)
            ->first();

        if ($override === null) {
            $override = new ContentAccessOverride([
                'user_id' => $userId,
                $column => $targetId,
                'granted_by' => $grantedBy,
                'note' => $note,
                'granted_at' => now(),
            ]);
            $override->tenant_id = $tenantId;
            $override->save();

            return $override;
        }

        $override->update([
            'granted_by' => $grantedBy,
            'note' => $note,
            'granted_at' => now(),
            'revoked_at' => null,
        ]);

        return $override;
    }

    /** Soft-revoke an override (kept for the audit trail). */
    public function revoke(ContentAccessOverride $override): void
    {
        if ($override->revoked_at === null) {
            $override->update(['revoked_at' => now()]);
        }
    }

    /**
     * The student's active override target-id sets.
     *
     * @return array{lessons: array<int, bool>, sections: array<int, bool>}
     */
    public function activeTargetSets(int $tenantId, int $userId): array
    {
        $rows = ContentAccessOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->active()
            ->get(['lesson_id', 'section_id']);

        $sets = ['lessons' => [], 'sections' => []];
        foreach ($rows as $row) {
            if ($row->lesson_id !== null) {
                $sets['lessons'][(int) $row->lesson_id] = true;
            }
            if ($row->section_id !== null) {
                $sets['sections'][(int) $row->section_id] = true;
            }
        }

        return $sets;
    }

    /**
     * Does an active override cover this section? (the section itself or its lesson;
     * unit targets retired — VD §7).
     *
     * @param  array{lessons: array<int, bool>, sections: array<int, bool>}  $sets
     */
    public function sectionCovered(array $sets, LessonSection $section): bool
    {
        return isset($sets['sections'][(int) $section->id])
            || ($section->lesson_id !== null && isset($sets['lessons'][(int) $section->lesson_id]));
    }

    /**
     * Does an active override cover this lesson?
     *
     * @param  array{lessons: array<int, bool>, sections: array<int, bool>}  $sets
     */
    public function lessonCovered(array $sets, Lesson $lesson): bool
    {
        return isset($sets['lessons'][(int) $lesson->id]);
    }

    /** Single-shot convenience: is this lesson override-unlocked for the user? */
    public function hasActiveForLesson(int $tenantId, int $userId, Lesson $lesson): bool
    {
        return $this->lessonCovered($this->activeTargetSets($tenantId, $userId), $lesson);
    }

    /** Single-shot convenience: is this section override-unlocked for the user? */
    public function hasActiveForSection(int $tenantId, int $userId, LessonSection $section): bool
    {
        return $this->sectionCovered($this->activeTargetSets($tenantId, $userId), $section);
    }
}
