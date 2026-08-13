<?php

namespace App\Modules\Engagement\Models;

use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PointsEntry extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'academic_year_id',
        'user_id', 'points', 'reason', 'ref_type', 'ref_id', 'idempotency_key',
    ];

    protected $casts = [
        'points' => 'integer',
    ];
}
