<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A teacher academy. GLOBAL entity (the tenant registry) — it is NOT itself
 * tenant-scoped and does not use BelongsToTenant/RLS. See 03_Data_Model.md §3.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'status',
        'owner_user_id',
        'dedicated_db_connection',
        'package_id',
        'trial_ends_at',
    ];

    protected $casts = [
        'status' => TenantStatus::class,
        'trial_ends_at' => 'datetime',
    ];

    /**
     * HasUuids fills the `uuid` column (not the bigint PK), leaving the
     * auto-incrementing primary key intact.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }
}
