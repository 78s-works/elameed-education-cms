<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * EDU-OPS-036: the two console surfaces used to verify a platform domain move.
 *
 * The move that prompted these tests retired `back.edu.raqeem-tech.com` in
 * favour of `edu.raqeem-tech.com` — a base domain that is a SUFFIX of the
 * retired one. A stored `<slug>.back.edu.raqeem-tech.com` therefore ends with
 * `.edu.raqeem-tech.com` while resolving nowhere, so any check built on a plain
 * suffix test calls a broken database clean.
 */
class TenantDomainCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'edu.raqeem-tech.com';

    private const RETIRED = 'back.edu.raqeem-tech.com';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['tenancy.base_domain' => self::BASE]);
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function domain(string $host, TenantDomainType $type = TenantDomainType::Subdomain): TenantDomain
    {
        return TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'host' => $host,
            'type' => $type->value,
            'is_primary' => $type === TenantDomainType::Subdomain,
        ]);
    }

    public function test_domains_lists_every_row_with_its_base_verdict(): void
    {
        $this->domain('demo.'.self::BASE);
        $this->domain('demo-custom.com', TenantDomainType::Custom);

        $this->artisan('tenancy:domains')
            ->expectsOutputToContain('Configured base domain: '.self::BASE)
            ->expectsOutputToContain('demo.'.self::BASE)
            ->expectsOutputToContain('demo-custom.com')
            ->assertSuccessful();
    }

    public function test_domains_flags_a_host_under_the_retired_base_as_stale(): void
    {
        $this->domain('demo.'.self::RETIRED);

        // Ends with '.edu.raqeem-tech.com', so a suffix test would pass it.
        $this->artisan('tenancy:domains --stale')
            ->expectsOutputToContain('demo.'.self::RETIRED)
            ->assertSuccessful();
    }

    public function test_domains_reports_nothing_stale_when_every_subdomain_is_one_label(): void
    {
        $this->domain('demo.'.self::BASE);
        // A custom domain never moves with the platform, so it is not stale.
        $this->domain('demo-custom.com', TenantDomainType::Custom);

        $this->artisan('tenancy:domains --stale')
            ->expectsOutputToContain('No stale rows')
            ->assertSuccessful();
    }

    public function test_domains_rejects_an_unknown_type(): void
    {
        $this->artisan('tenancy:domains --type=bogus')
            ->expectsOutputToContain('Unknown --type')
            ->assertFailed();
    }

    public function test_rebase_plans_a_host_under_the_retired_base(): void
    {
        $row = $this->domain('demo.'.self::RETIRED);

        $this->artisan('tenancy:rebase-subdomains --from='.self::RETIRED.' --apply')
            ->assertSuccessful();

        $this->assertSame('demo.'.self::BASE, $row->refresh()->host);
    }

    public function test_rebase_plans_a_retired_host_without_the_from_option(): void
    {
        $row = $this->domain('demo.'.self::RETIRED);

        // No --from: the first label is taken, so the two-level suffix collapses.
        $this->artisan('tenancy:rebase-subdomains --apply')
            ->assertSuccessful();

        $this->assertSame('demo.'.self::BASE, $row->refresh()->host);
    }

    public function test_rebase_leaves_a_correct_host_alone(): void
    {
        $row = $this->domain('demo.'.self::BASE);

        $this->artisan('tenancy:rebase-subdomains')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame('demo.'.self::BASE, $row->refresh()->host);
    }

    public function test_rebase_never_touches_a_custom_domain(): void
    {
        $row = $this->domain('demo-custom.com', TenantDomainType::Custom);

        $this->artisan('tenancy:rebase-subdomains --apply')->assertSuccessful();

        $this->assertSame('demo-custom.com', $row->refresh()->host);
    }
}
