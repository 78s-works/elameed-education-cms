<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Global identity. One user can hold different roles across tenants via
 * tenant_user (03_Data_Model.md §3). NOT tenant-scoped.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Roles and permissions are per-tenant (M20): the team id comes from
     * TenantTeamResolver, so every check below answers "in the current academy".
     */
    use HasRoles;

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

    /** The student profile (VD R5 study_mode + center); null for non-students. */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
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

    /**
     * The role names this user holds in the CURRENT academy (M20).
     *
     * Reads through Spatie, which scopes by the resolved team, so this answers
     * "here", never "anywhere" — a teacher in one academy and a student in
     * another must not carry the first academy's authority into the second.
     *
     * @return list<string>
     */
    public function roleNamesInTenant(): array
    {
        return $this->roles()->pluck('name')->values()->all();
    }

    /**
     * The union of the permissions granted by those roles.
     *
     * @return list<string>
     */
    public function permissionNamesInTenant(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function hasRoleInTenant(Tenant $tenant, TenantUserRole $role): bool
    {
        return $this->memberships()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', $role->value)
            ->exists();
    }
}
