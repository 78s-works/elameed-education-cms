<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership + role within one tenant. GLOBAL mapping table — not RLS-
 * scoped (see the migration for why). Table name is the conventional singular
 * `tenant_user`.
 *
 * @property int $tenant_id
 * @property int $user_id
 * @property TenantUserRole $role
 * @property MembershipStatus $status
 */
class TenantUser extends Model
{
    protected $table = 'tenant_user';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'role' => TenantUserRole::class,
        'status' => MembershipStatus::class,
        'joined_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
