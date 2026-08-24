<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\PermissionGroup;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Privilege-escalation guard for the team surface (M20).
 *
 * `team.*` lets the owner delegate assistant and role management. That delegation
 * must not become a ladder, so three rules hold regardless of what the routes
 * allow:
 *
 *   1. TEAM KEYS ARE OWNER-ONLY to grant. A delegated assistant can never put a
 *      `team.*` key in a role, nor assign a role carrying one — not even one they
 *      hold themselves. Otherwise one delegation seeds an unbounded chain.
 *   2. NOBODY GRANTS WHAT THEY DO NOT HOLD. Without this, an assistant with
 *      `team.roles.manage` could mint a role granting everything and take it.
 *   3. NOBODY ACTS ON SOMEONE WHO OUTRANKS THEM. The academy owner is out of
 *      reach of every assistant.
 *
 * Enforced server-side on purpose: hiding a toggle in the panel is not a control.
 */
class TeamAuthority
{
    public function __construct(private readonly TenantContext $context) {}

    /** The caller's membership in the current academy, or 403. */
    public function membership(Request $request): TenantUser
    {
        $user = $request->user();
        $tenant = $this->context->tenant();
        $membership = ($user !== null && $tenant !== null) ? $user->membershipFor($tenant) : null;

        if ($membership === null || ! $membership->isActive()) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }

        return $membership;
    }

    /**
     * Whether the caller is the academy owner — asked of the ROLE they hold, not
     * of the membership column, so authority has exactly one source.
     */
    public function isOwner(Request $request): bool
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $ownerRole = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('template_key', RoleTemplateKey::Teacher->value)
            ->first();

        if ($ownerRole === null) {
            return false;
        }

        return $this->membership($request)->holdsRole($ownerRole->name);
    }

    /**
     * Rule 1 + 2, applied to a set of permission keys.
     *
     * @param  list<string>  $permissionKeys
     */
    public function assertMayGrantPermissions(Request $request, array $permissionKeys): void
    {
        if ($this->isOwner($request)) {
            return;
        }

        $teamKeys = array_values(array_map(
            static fn (PermissionEnum $p): string => $p->value,
            array_filter(
                PermissionEnum::cases(),
                static fn (PermissionEnum $p): bool => $p->group() === PermissionGroup::Team,
            ),
        ));

        if (array_intersect($permissionKeys, $teamKeys) !== []) {
            throw new AccessDeniedHttpException(
                'Only the academy owner can grant team management.'
            );
        }

        $membership = $this->membership($request);
        $excess = array_values(array_filter(
            $permissionKeys,
            fn (string $key): bool => ! $membership->holdsPermission($key),
        ));

        if ($excess !== []) {
            throw new AccessDeniedHttpException(
                'You cannot grant permissions you do not hold yourself: '.implode(', ', $excess).'.'
            );
        }
    }

    /**
     * Same rules, applied to whole roles: a role is only assignable if every
     * permission inside it is.
     *
     * @param  Collection<int, Role>  $roles
     */
    public function assertMayAssignRoles(Request $request, Collection $roles): void
    {
        if ($this->isOwner($request)) {
            return;
        }

        $keys = $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        $this->assertMayGrantPermissions($request, $keys);
    }

    /**
     * Rule 3. The owner is untouchable by anyone else; everyone else is fair game
     * for a caller the routes already let through.
     */
    public function assertMayActOn(Request $request, TenantUser $target): void
    {
        if ($this->isOwner($request)) {
            return;
        }

        $tenantId = $this->context->tenantOrFail()->getKey();

        $ownerRole = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('template_key', RoleTemplateKey::Teacher->value)
            ->first();

        if ($ownerRole !== null && $target->holdsRole($ownerRole->name)) {
            throw new AccessDeniedHttpException(
                'You cannot act on the academy owner.'
            );
        }
    }
}
