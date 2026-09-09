<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Http\Controllers\StudentLibraryController;
use App\Modules\Commerce\Models\Enrollment;

/**
 * Per-request lookup of what the calling student already owns, so the public
 * catalogue can show every item yet swap the buy button for an "access" affordance
 * on lessons/packages the student has bought (Explore F4 follow-up).
 *
 * Access is always granted per-lesson (enrollment `lesson_id`); a package buy fans
 * out into per-lesson rows carrying the source `package_id` as provenance — so
 * "owns package X" = the student has an access-granting row with `package_id = X`
 * (mirrors {@see StudentLibraryController}).
 *
 * Registered as a singleton (CatalogServiceProvider) so the two id-sets are read
 * ONCE per request and reused across every resource row — no N+1 over a 20-item
 * catalogue page. Keyed by user id so a worker reusing the instance stays correct.
 */
class StudentOwnership
{
    /** @var array<int, array{lessons: array<int, true>, packages: array<int, true>}> */
    private array $cache = [];

    public function ownsLesson(int $userId, int $lessonId): bool
    {
        return isset($this->sets($userId)['lessons'][$lessonId]);
    }

    public function ownsPackage(int $userId, int $packageId): bool
    {
        return isset($this->sets($userId)['packages'][$packageId]);
    }

    /** @return array{lessons: array<int, true>, packages: array<int, true>} */
    private function sets(int $userId): array
    {
        return $this->cache[$userId] ??= [
            'lessons' => $this->flip($userId, 'lesson_id'),
            'packages' => $this->flip($userId, 'package_id'),
        ];
    }

    /** @return array<int, true> */
    private function flip(int $userId, string $column): array
    {
        return array_flip(
            Enrollment::query()
                ->where('user_id', $userId)
                ->grantsAccess()
                ->whereNotNull($column)
                ->pluck($column)
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }
}
