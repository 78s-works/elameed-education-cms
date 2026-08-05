<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\AccessMode;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recursive content Package (VD change set §7 LP-1/LP-2): the single grouping
 * that replaces Course + Unit + Bundle. Scoped to one tenant and one academic
 * year, it contains lessons and/or sub-packages (ordered) via {@see PackageItem}.
 *
 * Its `access_mode` is the channel ceiling every attached child must fit within
 * (LP-5/LP-D1); PackageItemService enforces that (plus the cycle + same-year
 * guards) at attach time. Bound by id under the tenant + academic-year scope,
 * exactly like a standalone Lesson.
 *
 * @property AccessMode $access_mode
 */
class Package extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;
    use HasUuids;

    /** In-memory default matching the DB default (so a fresh model has it). */
    protected $attributes = [
        'access_mode' => 'both',
    ];

    protected $fillable = [
        'name',
        'access_mode',
        'price_minor',
        'currency',
        'is_purchasable',
    ];

    protected $casts = [
        'access_mode' => AccessMode::class,
        'price_minor' => 'integer',
        'is_purchasable' => 'boolean',
    ];

    /** Only `uuid` is a generated UUID column; the PK stays an auto-increment bigint. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        // Deleting a package auto-detaches it from any PARENT package. Its own
        // items cascade via the package_id FK; but rows where this package is a
        // nested child (item_type=package, item_id=this) have no FK (item_id is
        // polymorphic), so remove them here. Scoped to the tenant, year-agnostic.
        static::deleting(function (Package $package): void {
            PackageItem::withoutGlobalScopes()
                ->where('tenant_id', $package->tenant_id)
                ->where('item_type', PackageItem::TYPE_PACKAGE)
                ->where('item_id', $package->id)
                ->delete();
        });
    }

    /** Ordered contents — lessons and sub-packages. */
    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Resolve a package_item to its concrete model — a Lesson or a sub-Package —
     * within the current tenant + academic-year scope. Returns null if the target
     * no longer exists.
     */
    public function resolveItem(PackageItem $item): Lesson|Package|null
    {
        return $item->item_type === PackageItem::TYPE_LESSON
            ? Lesson::find($item->item_id)
            : static::find($item->item_id);
    }
}
