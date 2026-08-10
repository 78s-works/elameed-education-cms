<?php

namespace App\Modules\Catalog\Enums;

/**
 * Hybrid center/online delivery mode (VD change set §7, LP-4/LP-5). Lives on the
 * three content levels — packages, lessons, and lesson parts (lesson_sections).
 *
 *   center — on-site only.
 *   online — remote only.
 *   both   — either channel.
 *
 * The subset (ceiling) rule constrains a child by its parent: a part may narrow
 * its lesson's channel but never widen it.
 *
 *   both   ⊇ { both, online, center }
 *   online ⊇ { online }
 *   center ⊇ { center }
 */
enum AccessMode: string
{
    case Center = 'center';
    case Online = 'online';
    case Both = 'both';

    /**
     * Is $this a valid child of $parent under the ceiling rule? `both` admits any
     * child; a specific parent admits only the same specific mode.
     */
    public function isSubsetOf(self $parent): bool
    {
        return $parent === self::Both || $this === $parent;
    }

    /**
     * Runtime part visibility (B12 / LP-6): is content of $this access_mode shown
     * to a student whose study_mode is $studyMode? `both` is a wildcard on EITHER
     * side — a hybrid (`both`) student sees every channel, and `both` content shows
     * to everyone; otherwise a single-channel student sees only their own channel.
     *
     *   both   student → { center, online, both }
     *   center student → { center, both }
     *   online student → { online, both }
     *
     * Unlike isSubsetOf() this is symmetric in `both`, so it is NOT the ceiling
     * rule reversed — a center student must still see `both` content.
     */
    public function isVisibleTo(self $studyMode): bool
    {
        return $this === self::Both || $studyMode === self::Both || $this === $studyMode;
    }
}
