<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

    /**
     * The academic years this membership is scoped to (M18 — assistants can serve
     * several years at once). Students pin their single year on StudentProfile
     * instead; this pivot is used for the assistant roster's year filter.
     */
    public function academicYears(): BelongsToMany
    {
        return $this->belongsToMany(AcademicYear::class, 'assistant_academic_year', 'tenant_user_id', 'academic_year_id')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * Whether the member holds a permission IN THIS ACADEMY (M20).
     *
     * Answered by the roles they hold, with no implicit authority for any
     * membership kind — that is the whole point of the move to roles. Pins the
     * team explicitly because callers include queue jobs and notifications,
     * where no tenant is resolved from a request.
     */
    public function holdsPermission(string $permission): bool
    {
        $user = $this->user;

        if ($user === null) {
            return false;
        }

        return $this->withTeam(function () use ($user, $permission): bool {
            try {
                return $user->hasPermissionTo($permission);
            } catch (PermissionDoesNotExist) {
                // A key the catalog no longer defines grants nothing, rather than
                // blowing up a notification fan-out.
                return false;
            }
        });
    }

    /**
     * Run a Spatie lookup with the team pinned to THIS membership's academy.
     *
     * Every role/permission read goes through here because callers include queue
     * jobs, seeders and notifications, where no tenant is resolved from a request
     * — and an answer computed against the wrong academy is a privilege leak.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTeam(callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->tenant_id);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * The uuids of the roles the panel may re-assign — i.e. everything except the
     * baseline role the observer owns. Sent so the editor can pre-tick boxes.
     *
     * @return list<string>
     */
    public function assignableRoleUuids(): array
    {
        $user = $this->user;

        if ($user === null) {
            return [];
        }

        // Grouped: without the closure the OR would escape the relation's own
        // constraints and pull in roles from outside this member (and academy).
        return $this->withTeam(fn (): array => $user->roles()
            ->where(fn ($q) => $q
                ->whereNull('template_key')
                ->orWhereNotIn('template_key', [
                    RoleTemplateKey::Teacher->value,
                    RoleTemplateKey::Assistant->value,
                    RoleTemplateKey::Student->value,
                    RoleTemplateKey::ParentGuardian->value,
                ]))
            ->pluck('uuid')
            ->values()
            ->all());
    }

    /**
     * Every permission key the member holds here, via their roles.
     *
     * @return list<string>
     */
    public function holdsPermissionKeys(): array
    {
        $user = $this->user;

        if ($user === null) {
            return [];
        }

        return $this->withTeam(fn (): array => $user->getAllPermissions()
            ->pluck('name')
            ->values()
            ->all());
    }

    /**
     * Whether this member is the academy OWNER (M20) — asked of the role they
     * hold, not of the membership kind, so there is exactly one source of
     * authority in the system.
     */
    public function isOwner(): bool
    {
        $ownerRole = Role::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('template_key', RoleTemplateKey::Teacher->value)
            ->value('name');

        return $ownerRole !== null && $this->holdsRole($ownerRole);
    }

    /** Whether the member holds a named role IN THIS ACADEMY (M20). */
    public function holdsRole(string $roleName): bool
    {
        $user = $this->user;

        if ($user === null) {
            return false;
        }

        return $this->withTeam(fn (): bool => $user->hasRole($roleName));
    }

    /**
     * The role names the member holds here.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        $user = $this->user;

        if ($user === null) {
            return [];
        }

        return $this->withTeam(fn (): array => $user->roles()->pluck('name')->values()->all());
    }
}
