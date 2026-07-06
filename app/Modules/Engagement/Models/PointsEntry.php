<?php

namespace App\Modules\Engagement\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PointsEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'points', 'reason', 'ref_type', 'ref_id', 'idempotency_key',
    ];

    protected $casts = [
        'points' => 'integer',
    ];
}
