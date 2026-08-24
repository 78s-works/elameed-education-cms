<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Http\Requests\StoreRoleRequest;
use App\Modules\Identity\Http\Requests\UpdateRoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Services\TenantRoleWriter;
use App\Modules\Identity\Support\TeamAuthority;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The academy's Roles & permissions surface (M20).
 *
 * Roles are per-academy copies of the platform templates plus whatever the
 * teacher authored. Two rules run through every write:
 *
 *   - System roles are read-only here. The owner cannot even widen their own
 *     role, so nobody can lock themselves out or quietly promote themselves.
 *   - A caller may only put permissions in a role that they hold themselves, and
 *     `team.*` only if they are the owner (TeamAuthority).
 *
 * The listing is scoped by the tenant column explicitly rather than trusting the
 * team resolver alone: isolation here must not depend on configuration.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TeamAuthority $authority,
        private readonly TenantRoleWriter $writer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->where('tenant_id', $this->context->tenantOrFail()->getKey())
            ->with('permissions')
            // Not withCount('users'): Spatie resolves that relation from the row's
            // own guard_name, which an aggregate query has not loaded — it blows up
            // with "Class name must be a valid object or a string". Count the pivot.
            ->withCount(['permissions'])
            ->addSelect(['users_count' => DB::table('model_has_roles')
                ->selectRaw('count(*)')
                ->whereColumn('model_has_roles.role_id', 'roles.id'),
            ])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => RoleResource::collection($roles)->resolve()]);
    }

    /** The platform permission catalog the picker renders, grouped and ordered. */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => PermissionEnum::catalog()]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $keys = PermissionEnum::sanitize($data['permissions'] ?? []);

        $this->authority->assertMayGrantPermissions($request, $keys);

        $role = $this->writer->create($tenantId, $data['name'], $data['description'] ?? null, $keys);

        app(AuditLogger::class)->log('role.created', [
            'role_uuid' => $role->uuid, 'name' => $role->name, 'permissions' => $keys,
        ], $tenantId, 'role', $role->getKey());

        return response()->json([
            'data' => (new RoleResource($role->load('permissions')))->resolve(),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, string $uuid): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $role = $this->roleOrFail($tenantId, $uuid);
        $data = $request->validated();

        $keys = array_key_exists('permissions', $data)
            ? PermissionEnum::sanitize($data['permissions'])
            : null;

        if ($keys !== null) {
            $this->authority->assertMayGrantPermissions($request, $keys);
        }

        $role = $this->writer->update($role, $data['name'] ?? null, $data['description'] ?? null, $keys);

        app(AuditLogger::class)->log('role.updated', array_filter([
            'role_uuid' => $role->uuid, 'name' => $role->name, 'permissions' => $keys,
        ], fn ($v) => $v !== null), $tenantId, 'role', $role->getKey());

        return response()->json(['data' => (new RoleResource($role->load('permissions')))->resolve()]);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $role = $this->roleOrFail($tenantId, $uuid);

        // Deleting a role a delegate could not have created is the same escalation
        // in reverse — it silently strips colleagues of powers the caller lacks.
        $this->authority->assertMayGrantPermissions(
            $request,
            $role->permissions->pluck('name')->all(),
        );

        $this->writer->delete($role);

        app(AuditLogger::class)->log('role.deleted', [
            'role_uuid' => $uuid, 'name' => $role->name,
        ], $tenantId, 'role', $role->getKey());

        return response()->noContent();
    }

    /** Bound by uuid within the caller's academy — another academy's role 404s. */
    private function roleOrFail(int $tenantId, string $uuid): Role
    {
        $role = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->with('permissions')
            ->first();

        if ($role === null) {
            throw new DomainException('role_not_found', __('Role not found.'), 404);
        }

        return $role;
    }
}
