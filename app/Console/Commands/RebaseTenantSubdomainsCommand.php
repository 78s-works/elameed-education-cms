<?php

namespace App\Console\Commands;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\HostNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repoints platform SUBDOMAIN rows in `tenant_domains` from an old base domain
 * onto the configured one — the data half of a platform domain move (e.g.
 * a retired base domain onto `edu.raqeem-tech.com`). Config alone only fixes hosts the
 * app *derives*; rows already stored keep the old suffix and stop resolving.
 *
 * Only `type = subdomain` rows are touched: a teacher's CUSTOM domain is their
 * own property and never moves with the platform. Defaults to a dry run.
 */
class RebaseTenantSubdomainsCommand extends Command
{
    protected $signature = 'tenancy:rebase-subdomains
        {--from= : Retired base domain to replace. Defaults to every suffix that is not the target.}
        {--to= : New base domain. Defaults to config("tenancy.base_domain").}
        {--apply : Write the changes. Without this the command only reports.}';

    protected $description = 'Move platform subdomain hosts onto the configured tenancy base domain.';

    public function handle(): int
    {
        $to = HostNormalizer::normalize((string) ($this->option('to') ?: config('tenancy.base_domain')));

        if ($to === '') {
            $this->error('No target base domain: pass --to or set TENANCY_BASE_DOMAIN.');

            return self::FAILURE;
        }

        $from = $this->option('from')
            ? HostNormalizer::normalize((string) $this->option('from'))
            : null;

        $rows = TenantDomain::query()
            ->where('type', TenantDomainType::Subdomain->value)
            ->orderBy('id')
            ->get();

        $planned = [];

        foreach ($rows as $row) {
            $host = HostNormalizer::normalize((string) $row->host);

            if ($host === '' || Str::endsWith($host, '.'.$to)) {
                continue; // already on the target base domain
            }

            // The label is everything before the old base domain. With --from we
            // only touch rows carrying that exact suffix; without it, any row not
            // already on the target is rebased using its FIRST label.
            if ($from !== null) {
                if (! Str::endsWith($host, '.'.$from)) {
                    continue;
                }

                $label = Str::beforeLast($host, '.'.$from);
            } else {
                $label = Str::before($host, '.');
            }

            if ($label === '' || str_contains($label, '.')) {
                $this->warn("Skipped {$host}: cannot derive a single subdomain label.");

                continue;
            }

            $planned[] = [$row, $host, $label.'.'.$to];
        }

        if ($planned === []) {
            $this->info('Nothing to do — every subdomain row is already on '.$to.'.');

            return self::SUCCESS;
        }

        $this->table(['tenant_id', 'from', 'to'], array_map(
            static fn (array $p): array => [$p[0]->tenant_id, $p[1], $p[2]],
            $planned
        ));

        if (! $this->option('apply')) {
            $this->comment('Dry run — re-run with --apply to write '.count($planned).' row(s).');

            return self::SUCCESS;
        }

        // A collision would violate the unique host index; report it instead of
        // failing halfway through.
        $targets = array_map(static fn (array $p): string => $p[2], $planned);
        $taken = TenantDomain::query()
            ->whereIn('host', $targets)
            ->pluck('host')
            ->all();

        if ($taken !== []) {
            $this->error('Target host(s) already exist: '.implode(', ', $taken));

            return self::FAILURE;
        }

        DB::transaction(function () use ($planned): void {
            foreach ($planned as [$row, , $target]) {
                $row->forceFill(['host' => $target])->save();
            }
        });

        // The host → tenant map and the domain guard both cache by host; the
        // TenantDomain observer busts the OLD key on save, so clear the store to
        // drop any negative entry cached for the new host.
        $this->call('cache:clear');

        $this->info('Rebased '.count($planned).' subdomain row(s) onto '.$to.'.');

        return self::SUCCESS;
    }
}
