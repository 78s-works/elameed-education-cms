<?php

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\PlatformAdmin\Http\Requests\StoreTenantRequest;
use App\Modules\PlatformAdmin\Http\Requests\UpdateTenantRequest;
use App\Modules\PlatformAdmin\Http\Resources\AdminTenantResource;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * /admin/tenants (M01, FR-M01-02) — the platform admin's teacher lifecycle:
 * create/list/edit teachers and set status. Cross-tenant; not tenant-scoped.
 */
class AdminTenantController
{
    public function index(): AnonymousResourceCollection
    {
        return AdminTenantResource::collection(
            Tenant::query()->with('domains')->latest()->paginate(30)
        );
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tenant = DB::transaction(function () use ($data): Tenant {
            $tenant = Tenant::create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'status' => $data['status'] ?? TenantStatus::Active->value,
            ]);

            TenantDomain::create([
                'tenant_id' => $tenant->id,
                'host' => $data['slug'].'.'.config('tenancy.base_domain', 'elameed.app'),
                'type' => TenantDomainType::Subdomain->value,
                'is_primary' => true,
            ]);

            if (! empty($data['owner'])) {
                $owner = User::firstOrCreate(
                    ['phone' => $data['owner']['phone']],
                    [
                        'name' => $data['owner']['name'],
                        'email' => $data['owner']['email'] ?? null,
                        'password' => $data['owner']['password'],
                        'phone_verified_at' => now(),
                    ],
                );

                TenantUser::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => TenantUserRole::Teacher->value],
                    ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
                );

                $tenant->forceFill(['owner_user_id' => $owner->id])->save();
            }

            return $tenant;
        });

        return (new AdminTenantResource($tenant->load('domains')))->response()->setStatusCode(201);
    }

    public function show(Tenant $tenant): AdminTenantResource
    {
        return new AdminTenantResource($tenant->load('domains'));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): AdminTenantResource
    {
        $tenant->update($request->validated());

        app(AuditLogger::class)->log('tenant.updated', [
            'tenant' => $tenant->slug,
            'changes' => $request->validated(),
        ], $tenant->id, 'tenant', $tenant->id);

        return new AdminTenantResource($tenant->load('domains'));
    }
}
