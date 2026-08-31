<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Http\Requests\UpdateRoleTemplateRequest;
use App\Modules\Identity\Http\Resources\RoleTemplateResource;
use App\Modules\Identity\Models\RoleTemplate;
use App\Modules\Identity\Services\TenantRoleProvisioner;
use App\Support\Audit\AuditLogger;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * What pressing "push" would change, per academy — named, itemised, and
     * flagged where the academy customised its copy. Read-only.
     */
    public function resyncPreview(RoleTemplate $roleTemplate, TenantRoleProvisioner $provisioner): JsonResponse
    {
        $academies = $provisioner->previewTemplateResync($roleTemplate->load('permissions'));

        // An archived academy still holds a copy, but it is not what an admin is
        // deciding about — it is listed and flagged, and kept out of the totals
        // so the headline count matches what a push would actually reach.
        $live = array_values(array_filter($academies, fn ($a) => ! $a['tenant_deleted']));

        return response()->json(['data' => [
            'template' => ['name' => $roleTemplate->name, 'key' => $roleTemplate->key->value],
            'academies' => $academies,
            'total' => count($live),
            'archived_total' => count($academies) - count($live),
            'customised_total' => count(array_filter($live, fn ($a) => $a['customised'])),
            // Stated rather than implied: the push replaces the copy outright.
            'overwrites_local_changes' => true,
        ]]);
    }

    /**
     * Overwrite academies' copies of this template with its current set.
     * Destructive on purpose: teachers who tuned that role lose their changes,
     * so the caller may scope it to the academies they confirmed, and every
     * academy touched gets its own audit entry.
     */
    public function resync(Request $request, RoleTemplate $roleTemplate, TenantRoleProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'tenants' => ['nullable', 'array'],
            'tenants.*' => ['string', 'uuid'],
        ]);

        $touched = $provisioner->resyncTemplateEverywhere(
            $roleTemplate->load('permissions'),
            $data['tenants'] ?? null,
        );

        foreach ($touched as $row) {
            app(AuditLogger::class)->log(
                'role_template.pushed',
                [
                    'template' => $roleTemplate->key->value,
                    'academy' => $row['tenant_name'],
                    'role_id' => $row['role_id'],
                ],
                $row['tenant_id'],
                'role_template',
                (int) $roleTemplate->getKey(),
            );
        }

        return response()->json(['data' => [
            'roles_updated' => count($touched),
            'academies' => array_values(array_map(fn ($r) => $r['tenant_name'], $touched)),
        ]]);
    }
}
