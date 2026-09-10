<?php

namespace App\Console\Commands;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\HostNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only listing of the host → tenant rows. Verifying a platform domain move
 * (EDU-OPS-036) otherwise needs `tinker --execute`, which the Plesk/Windows PHP
 * wrapper mangles into a parse error, and `tenancy:rebase-subdomains` reports
 * only the rows it would MOVE — never the hosts already stored.
 *
 * The `on base` column answers the question a suffix test gets wrong: a stored
 * subdomain is reachable only when it is a SINGLE label under the base domain.
 * `<slug>.back.edu.raqeem-tech.com` ends with `.edu.raqeem-tech.com` yet
 * resolves nowhere.
 */
class ListTenantDomainsCommand extends Command
{
    protected $signature = 'tenancy:domains
        {--type= : Limit to one type (subdomain|custom)}
        {--stale : Only subdomain rows that are NOT a single label under the configured base}';

    protected $description = 'List the host → tenant rows in tenant_domains';

    public function handle(): int
    {
        $type = $this->option('type');

        if ($type !== null && TenantDomainType::tryFrom((string) $type) === null) {
            $this->error('Unknown --type: pass subdomain or custom.');

            return self::FAILURE;
        }

        $base = HostNormalizer::normalize((string) config('tenancy.base_domain'));

        $this->line('Configured base domain: '.($base !== '' ? $base : '(unset)'));

        $rows = TenantDomain::query()
            ->with('tenant:id,slug')
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->orderBy('id')
            ->get();

        $table = [];

        foreach ($rows as $row) {
            // Read the raw column, never the enum cast: a legacy/out-of-enum
            // value would throw on access, and a listing must degrade instead.
            $rawType = (string) $row->getRawOriginal('type');
            $isSubdomain = $rawType === TenantDomainType::Subdomain->value;

            $host = HostNormalizer::normalize((string) $row->host);
            $onBase = HostNormalizer::subdomainLabel($host, $base) !== null;

            if ($this->option('stale') && (! $isSubdomain || $onBase)) {
                continue;
            }

            $table[] = [
                $row->tenant?->slug ?? '#'.$row->tenant_id,
                $host,
                TenantDomainType::present($rawType) ?? '?',
                $row->is_primary ? 'yes' : '',
                $isSubdomain ? ($onBase ? 'yes' : 'NO') : 'n/a',
            ];
        }

        if ($table === []) {
            $this->info($this->option('stale')
                ? 'No stale rows — every subdomain is a single label under '.$base.'.'
                : 'No rows matched.');

            return self::SUCCESS;
        }

        $this->table(['tenant', 'host', 'type', 'primary', 'on base'], $table);

        return self::SUCCESS;
    }
}
