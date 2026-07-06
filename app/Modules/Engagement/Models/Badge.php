<?php

namespace App\Modules\Engagement\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'description', 'icon', 'points_threshold',
    ];

    protected $casts = [
        'points_threshold' => 'integer',
    ];
}
