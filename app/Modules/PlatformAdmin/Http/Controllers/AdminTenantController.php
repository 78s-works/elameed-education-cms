<?php

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\PlatformAdmin\Http\Requests\StoreTenantRequest;
use App\Modules\PlatformAdmin\Http\Requests\UpdateTenantRequest;
use App\Modules\PlatformAdmin\Http\Resources\AdminTenantResource;
use App\Modules\PlatformAdmin\Services\TenantInsights;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * /admin/tenants (M01, FR-M01-02) — the platform admin's teacher lifecycle:
 * create/list/edit teachers and set status. Cross-tenant; not tenant-scoped.
 */
class AdminTenantController
{
    public function __construct(private readonly TenantInsights $insights) {}

    /**
     * Status chips alone stop working the moment there are more academies than
     * fit on a screen, so the list also searches the four things an admin
     * actually knows about an academy: its name, its subdomain slug, its
     * owner's name, and any domain pointed at it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->with(['domains', 'owner:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            // Subscription state, so the platform-business figures on /admin can
            // link straight to "the academies behind this number".
            ->when($request->query('subscription'), function ($q, $state): void {
                $q->whereHas('subscriptions', fn ($s) => $s->where('status', $state));
            })
            ->when($request->query('q'), function ($q, $term): void {
                $like = '%'.$term.'%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', $like))
                        ->orWhereHas('domains', fn ($d) => $d->where('host', 'like', $like));
                });
            })
            ->latest()
            ->paginate(min(100, max(10, (int) $request->query('per_page', 30))))
            ->withQueryString();

        return AdminTenantResource::collection($tenants);
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
                'host' => $data['slug'].'.'.config('tenancy.base_domain', 'edu.raqeem-tech.com'),
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

    /**
     * Full cross-tenant 360 of one academy: tenant + owner teacher + branding +
     * subscription/usage + activity stats (FR-M01, FR-M17). The list endpoint
     * stays lightweight; this detail view is the "all information" surface.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => $this->insights->detail($tenant)]);
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
