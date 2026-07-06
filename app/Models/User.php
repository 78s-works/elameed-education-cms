<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global identity. One user can hold different roles across tenants via
 * tenant_user (03_Data_Model.md §3). NOT tenant-scoped.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** HasUuids fills the `uuid` column; the bigint `id` stays the primary key. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /** The membership (if any) linking this user to the given tenant. */
    public function membershipFor(Tenant $tenant): ?TenantUser
    {
        return $this->memberships()->where('tenant_id', $tenant->getKey())->first();
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function hasRoleInTenant(Tenant $tenant, TenantUserRole $role): bool
    {
        return $this->memberships()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', $role->value)
            ->exists();
    }
}
