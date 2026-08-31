<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Modules\Billing\Models\TenantSubscriptionCharge;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * /admin/tenants/{tenant}/billing (ADM-16) — has this academy actually paid us?
 *
 * The plan card above it says what the academy is subscribed to; this says what
 * they have handed over. The two are reported together, including the custom
 * price and the reason recorded when it was set, so the section can never
 * contradict the card.
 */
class TenantBillingController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Tenant $tenant): JsonResponse
    {
        $tenantId = (int) $tenant->getKey();
        $subscription = $this->subscriptions->current($tenantId);

        $charges = TenantSubscriptionCharge::query()
            ->where('tenant_id', $tenantId)
            ->with('recorder:id,name')
            ->orderByDesc('charged_at')
            ->orderByDesc('id')
            ->get();

        $paid = $charges->where('status', 'paid');

        return response()->json(['data' => [
            'charges' => $charges->map(fn (TenantSubscriptionCharge $c) => [
                'uuid' => $c->uuid,
                'amount_minor' => $c->amount_minor,
                'currency' => $c->currency,
                'status' => $c->status,
                'method' => $c->method,
                'charged_at' => $c->charged_at?->toIso8601String(),
                'period_start' => $c->period_start?->toIso8601String(),
                'period_end' => $c->period_end?->toIso8601String(),
                'reference' => $c->reference,
                'notes' => $c->notes,
                'recorded_by' => $c->recorder?->name,
            ])->values(),
            'summary' => [
                // The subscription's own state is the single source of truth for
                // "is this account current"; the console must not re-derive it.
                'subscription_status' => $subscription?->status->value,
                'plan_name' => $subscription?->package?->name,
                'plan_price_minor' => $subscription?->package?->price_minor,
                'charged_price_minor' => $subscription?->price_minor,
                // A price below the plan's is a discount someone granted; the
                // reason was recorded at the moment it was set.
                'discount_reason' => $subscription?->meta['discount_reason'] ?? null,
                'renews_at' => $subscription?->renews_at?->toIso8601String(),
                'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
                'total_paid_minor' => (int) $paid->sum('amount_minor'),
                'charges_count' => $charges->count(),
                'last_paid_at' => $paid->first()?->charged_at?->toIso8601String(),
                'has_failed' => $charges->contains(fn ($c) => in_array($c->status, ['failed', 'pending'], true)),
                'currency' => $subscription?->currency ?? 'EGP',
            ],
        ]]);
    }

    /**
     * Record a charge by hand. Platform plans are settled by transfer, so an
     * admin entering what arrived IS the payment record — there is no gateway
     * callback to wait for.
     */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', Rule::in(TenantSubscriptionCharge::STATUSES)],
            'method' => ['nullable', Rule::in(TenantSubscriptionCharge::METHODS)],
            'charged_at' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'reference' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenantId = (int) $tenant->getKey();
        $subscription = $this->subscriptions->current($tenantId);

        $charge = TenantSubscriptionCharge::create([
            'tenant_id' => $tenantId,
            'tenant_subscription_id' => $subscription?->getKey(),
            'amount_minor' => $data['amount_minor'],
            'currency' => $data['currency'] ?? $subscription?->currency ?? 'EGP',
            'status' => $data['status'] ?? 'paid',
            'method' => $data['method'] ?? 'manual',
            'charged_at' => $data['charged_at'] ?? now(),
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => Auth::id(),
        ]);

        app(AuditLogger::class)->log(
            'tenant.subscription.charge_recorded',
            [
                'tenant' => $tenant->slug,
                'amount_minor' => $charge->amount_minor,
                'status' => $charge->status,
                'method' => $charge->method,
            ],
            $tenantId,
            'tenant_subscription_charge',
            (int) $charge->getKey(),
        );

        return response()->json(['data' => ['uuid' => $charge->uuid]], 201);
    }
}
