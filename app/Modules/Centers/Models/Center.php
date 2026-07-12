<?php

namespace App\Modules\Centers\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical teaching center (M12). Tenant-scoped.
 */
class Center extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
