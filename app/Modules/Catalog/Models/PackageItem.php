<?php

namespace App\Modules\Catalog\Models;

use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered entry inside a {@see Package}: EITHER a lesson OR a sub-package
 * (`item_type` says which; `item_id` is that model's internal id).
 *
 * Not an Eloquent morphTo — the two targets live in different tables and the
 * `item_type` values are domain tokens (lesson|package), not class names — so
 * resolution is explicit (see Package::resolveItem / PackageItemService). It
 * carries its own `academic_year_id` (site-wide year-scoping, Phase 1), always
 * equal to the parent package's year.
 */
class PackageItem extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    public const TYPE_LESSON = 'lesson';

    public const TYPE_PACKAGE = 'package';

    protected $fillable = [
        'package_id',
        'academic_year_id',
        'item_type',
        'item_id',
        'sort_order',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
