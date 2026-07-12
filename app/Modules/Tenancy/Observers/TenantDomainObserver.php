<?php

namespace App\Modules\Tenancy\Observers;

use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantDomainRegistry;

/**
 * Flushes the registered-domain cache whenever a host mapping is added, changed,
 * or removed, so a newly-added domain stops 404-ing (and a removed one starts)
 * without waiting for the TTL to lapse.
 */
class TenantDomainObserver
{
    public function __construct(private readonly TenantDomainRegistry $registry) {}

    public function saved(TenantDomain $domain): void
    {
        $this->registry->forgetHost($domain->host);

        // A renamed host must also clear its previous key.
        $original = $domain->getOriginal('host');

        if (is_string($original) && $original !== '' && $original !== $domain->host) {
            $this->registry->forgetHost($original);
        }
    }

    public function deleted(TenantDomain $domain): void
    {
        $this->registry->forgetHost($domain->host);
    }
}
