<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Http\Requests\UpdateRoleTemplateRequest;
use App\Modules\Identity\Http\Resources\RoleTemplateResource;
use App\Modules\Identity\Models\RoleTemplate;
use App\Modules\Identity\Services\TenantRoleProvisioner;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * Platform-admin management of the role templates (M20).
 *
 * The templates are the ONLY roles the platform admin touches. A tenant's copies
 * belong to that academy: once stamped, an edit here does not reach them, and a
 * role the teacher authored is never visible from this console at all.
 *
 * Pushing a template change onto existing academies is a separate, explicit act
 * (`resync`) precisely because it overwrites what those teachers configured.
 *
 * The owner template is the one exception to editability: its permission set is
 * derived from the code catalog on every sync, so accepting an edit here would
 * be a lie — it would be reverted on the next deploy. Name and description are
 * still editable.
 */
class RoleTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = RoleTemplate::with('permissions')->orderBy('sort_order')->get();

        return response()->json(['data' => RoleTemplateResource::collection($templates)->resolve()]);
    }

    /** The catalog the template editor renders. Same source as the teacher panel. */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => PermissionEnum::catalog()]);
    }

    public function update(UpdateRoleTemplateRequest $request, RoleTemplate $roleTemplate): JsonResponse
    {
        $data = $request->validated();

        $roleTemplate->fill(array_filter(
            ['name' => $data['name'] ?? null, 'description' => $data['description'] ?? null],
            static fn ($value): bool => $value !== null,
        ))->save();

        if (array_key_exists('permissions', $data)) {
            if ($roleTemplate->key === RoleTemplateKey::Teacher) {
                throw new DomainException(
                    'owner_template_is_derived',
                    __('The academy-owner template always holds every permission and cannot be edited.'),
                    422,
                );
            }

            $ids = Permission::query()
                ->whereIn('name', PermissionEnum::sanitize($data['permissions']))
                ->pluck('id')
                ->all();

            $roleTemplate->permissions()->sync($ids);
        }

        return response()->json([
            'data' => (new RoleTemplateResource($roleTemplate->load('permissions')))->resolve(),
        ]);
    }

    /**
     * Overwrite every academy's copy of this template with its current set.
     * Destructive on purpose: teachers who tuned that role lose their changes.
     */
    public function resync(RoleTemplate $roleTemplate, TenantRoleProvisioner $provisioner): JsonResponse
    {
        $count = $provisioner->resyncTemplateEverywhere($roleTemplate->load('permissions'));

        return response()->json(['data' => ['roles_updated' => $count]]);
    }
}
