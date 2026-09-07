<?php

namespace App\Modules\Tenancy\Observers;

use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use App\Modules\Notifications\Support\StaffRecipients;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantDomainRegistry;

/**
 * Flushes the registered-domain cache whenever a host mapping is added, changed,
 * or removed, so a newly-added domain stops 404-ing (and a removed one starts)
 * without waiting for the TTL to lapse.
 *
 * It is also where "your custom domain is verified" is announced. Verification
 * is not something the app decides — it lands from outside (the CDN's custom
 * hostname check), so the notification hangs off the moment `verified_at` is
 * filled in rather than off any one controller. Whoever writes that column —
 * webhook, admin action or a future sync — announces it by doing so.
 */
class TenantDomainObserver
{
    public function __construct(
        private readonly TenantDomainRegistry $registry,
        private readonly NotificationEngineService $engine,
    ) {}

    public function saved(TenantDomain $domain): void
    {
        $this->registry->forgetHost($domain->host);

        // A renamed host must also clear its previous key.
        $original = $domain->getOriginal('host');

        if (is_string($original) && $original !== '' && $original !== $domain->host) {
            $this->registry->forgetHost($original);
        }

        $this->announceVerification($domain);
    }

    public function deleted(TenantDomain $domain): void
    {
        $this->registry->forgetHost($domain->host);
    }

    /** Fires once — on the save that turns `verified_at` from null into a date. */
    private function announceVerification(TenantDomain $domain): void
    {
        if ($domain->verified_at === null || ! $domain->wasChanged('verified_at')) {
            return;
        }

        if ($domain->getOriginal('verified_at') !== null) {
            return; // a re-verification, not the news
        }

        $owners = StaffRecipients::owners((int) $domain->tenant_id);

        if ($owners === []) {
            return;
        }

        $this->engine->dispatch(
            notificationKey: 'domains.custom_domain.verified',
            tenantId: (int) $domain->tenant_id,
            recipientUserIds: $owners,
            renderVariables: ['domain' => (string) $domain->host],
            entityType: 'tenant_domain',
            entityId: $domain->getKey(),
            auditPayload: ['host' => $domain->host],
        );
    }
}
