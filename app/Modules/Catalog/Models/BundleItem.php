<?php

namespace App\Modules\Catalog\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry inside a {@see Bundle}: either a course or a unit. `item_type` says
 * which; the matching FK (course_id / unit_id) is set and the other is null.
 */
class BundleItem extends Model
{
    use BelongsToTenant;

    public const TYPE_COURSE = 'course';

    public const TYPE_UNIT = 'unit';

    protected $fillable = [
        'bundle_id',
        'item_type',
        'course_id',
        'unit_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
