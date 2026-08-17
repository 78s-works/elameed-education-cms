<?php

namespace App\Support\Traits;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use Illuminate\Database\Eloquent\Builder;

/**
 * A model that points at EITHER a standalone lesson OR a recursive package — the
 * two content anchors that replaced the retired `courses` entity (VD §7). Mirrors
 * {@see \App\Modules\Catalog\Models\PackageItem}'s domain-token convention:
 * `target_type` holds the token 'lesson'|'package' (NOT a class name), `target_id`
 * the target's internal id. The pair is nullable; a null target means "no specific
 * content" (e.g. a cart-wide coupon) — required-ness is enforced per consumer.
 *
 * Tokens are kept identical to PackageItem::TYPE_LESSON / TYPE_PACKAGE.
 */
trait HasContentTarget
{
    public const TARGET_LESSON = 'lesson';

    public const TARGET_PACKAGE = 'package';

    /** Resolve the pointed-at content model, or null when unset/unknown. */
    public function target(): Lesson|Package|null
    {
        return match ($this->target_type) {
            self::TARGET_LESSON => Lesson::find($this->target_id),
            self::TARGET_PACKAGE => Package::find($this->target_id),
            default => null,
        };
    }

    /** Scope to rows targeting a specific content model. */
    public function scopeForTarget(Builder $query, string $type, int $id): Builder
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }

    /** The valid target-type tokens (validation source of truth). */
    public static function targetTypes(): array
    {
        return [self::TARGET_LESSON, self::TARGET_PACKAGE];
    }
}
