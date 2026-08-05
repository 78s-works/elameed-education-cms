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
}
