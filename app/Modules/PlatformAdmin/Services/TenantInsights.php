<?php

namespace App\Modules\PlatformAdmin\Services;

use App\Modules\Billing\Http\Resources\TenantSubscriptionResource;
use App\Modules\Billing\Services\PackageUsage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;

/**
 * Aggregates the full cross-tenant view of one academy for the platform admin:
 * the tenant, its owner teacher, branding, subscription + usage, and activity
 * stats. Runs on the admin surface (outside the tenant group), so every
 * tenant-scoped read uses an explicit tenant_id — the BelongsToTenant global
 * scope is inert with no resolved tenant, so `withoutGlobalScopes()` + an
 * explicit filter is the safe, deliberate cross-tenant path.
 */
class TenantInsights
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PackageUsage $usage,
    ) {}

    /** @return array<string, mixed> */
    public function detail(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->getKey();
        $tenant->loadMissing(['domains', 'owner']);

        $profile = TeacherProfile::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $subscription = $this->subscriptions->current($tenantId);

        return [
            'tenant' => [
                'uuid' => $tenant->uuid,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status->value,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'created_at' => $tenant->created_at?->toIso8601String(),
                'domains' => $tenant->domains->map(fn ($domain) => [
                    'host' => $domain->host,
                    'type' => $domain->type instanceof \BackedEnum ? $domain->type->value : $domain->type,
                    'is_primary' => (bool) $domain->is_primary,
                ])->values(),
            ],
            'owner' => $this->owner($tenant),
            'branding' => $this->branding($profile),
            'subscription' => $subscription ? (new TenantSubscriptionResource($subscription))->resolve() : null,
            'usage' => $this->usage->forTenant($tenantId, $subscription?->package),
            'stats' => $this->stats($tenantId),
        ];
    }

    /** @return array<string, mixed>|null */
    private function owner(Tenant $tenant): ?array
    {
        $owner = $tenant->owner;

        if ($owner === null) {
            return null;
        }

        return [
            'uuid' => $owner->uuid,
            'name' => $owner->name,
            'phone' => $owner->phone,
            'email' => $owner->email,
            'locale' => $owner->locale,
            'created_at' => $owner->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function branding(?TeacherProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'logo_url' => $profile->logo_url,
            'cover_url' => $profile->cover_url,
            'primary_color' => $profile->primary_color,
            'secondary_color' => $profile->secondary_color,
            'bio' => $profile->bio,
            'contact' => $profile->contact,
            'socials' => $profile->socials,
            'layout' => $profile->layout,
        ];
    }

    /** @return array<string, int> */
    private function stats(int $tenantId): array
    {
        $members = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MembershipStatus::Active->value)
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'students' => (int) ($members[TenantUserRole::Student->value] ?? 0),
            'assistants' => (int) ($members[TenantUserRole::Assistant->value] ?? 0),
            'parents' => (int) ($members[TenantUserRole::Parent->value] ?? 0),
            'courses' => (int) Course::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'published_courses' => (int) Course::withoutGlobalScopes()->where('tenant_id', $tenantId)->published()->count(),
            'enrollments' => (int) Enrollment::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'gross_earnings_minor' => (int) LedgerEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('account', LedgerEntry::TEACHER_EARNINGS)
                ->where('direction', LedgerEntry::CREDIT)
                ->sum('amount_minor'),
        ];
    }
}
