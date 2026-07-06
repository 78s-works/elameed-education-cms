<?php

namespace App\Modules\Engagement\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentBadge extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'user_id', 'badge_id', 'awarded_at',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
    ];

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
