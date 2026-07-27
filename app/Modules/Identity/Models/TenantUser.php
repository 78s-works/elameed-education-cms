<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission;
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
        'permissions',
        'joined_at',
    ];

    protected $casts = [
        'role' => TenantUserRole::class,
        'status' => MembershipStatus::class,
        'permissions' => 'array',
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

    /**
     * Whether this membership grants a permission (M18). Teachers (academy
     * owners) hold every permission implicitly; assistants only the ones granted
     * on their row; any other role holds none.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->role === TenantUserRole::Teacher) {
            return true;
        }

        if ($this->role !== TenantUserRole::Assistant) {
            return false;
        }

        return in_array($permission, (array) ($this->permissions ?? []), true);
    }

    /**
     * The effective permission set (teachers = the full catalog; assistants =
     * the granted subset, intersected with the catalog so retired keys drop out).
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        if ($this->role === TenantUserRole::Teacher) {
            return Permission::values();
        }

        return array_values(array_intersect(Permission::values(), (array) ($this->permissions ?? [])));
    }
}
