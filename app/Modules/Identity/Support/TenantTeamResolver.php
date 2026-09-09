<?php

namespace App\Modules\Identity\Support;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Binds spatie/laravel-permission's "team" to our tenant (M20).
 *
 * The package scopes every role lookup and assignment to a team id; we point
 * that at the resolved academy, so a role named "Finance" in academy A is a
 * different row — and a different grant — from the one in academy B.
 *
 * Reads TenantContext (set by ResolveTenant) on every call rather than caching,
 * because a single process can serve several tenants: queue workers, the
 * platform-admin console, Octane and tests all switch tenants mid-process.
 *
 * setPermissionsTeamId() is still honoured when something sets it explicitly
 * (seeders and the tenant provisioner do, before any request context exists);
 * an explicit value wins over the request tenant until it is cleared.
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    protected int|string|null $teamId = null;

    /** The team the last lookup answered for — see clearStaleRelations(). */
    protected int|string|null $lastResolved = null;

    /** @param  Model|int|string|null  $id */
    public function setPermissionsTeamId($id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->teamId = $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        $team = $this->teamId ?? app(TenantContext::class)->tenantId();

        if ($team !== $this->lastResolved) {
            $this->clearStaleRelations();
            $this->lastResolved = $team;
        }

        return $team;
    }

    /**
     * Drop the authenticated user's cached role/permission relations when the
     * team changes.
     *
     * Spatie answers `hasPermissionTo` from the model's LOADED relations. A user
     * object that survives a team switch — the same instance reused across two
     * requests under Octane, a queue job looping over academies, a test hitting
     * two tenants — would otherwise be judged against the previous academy's
     * roles. That is a cross-tenant authority leak, not a caching detail, so the
     * relations are forgotten the moment the team moves.
     */
    protected function clearStaleRelations(): void
    {
        $user = app('auth')->hasUser() ? app('auth')->user() : null;

        if ($user === null) {
            return;
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
