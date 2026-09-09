<?php

namespace Tests\Feature;

use Database\Seeders\AhmedTammamAcademySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * EDU-034: production holds real data only. `db:seed` there must produce the
 * catalogs and nothing else; the demo academies need an explicit SEED_DEMO=true.
 */
class DemoSeedGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a seeder in-process. `$this->seed()` goes through `db:seed`, which
     * stops for a confirmation prompt once the environment is production.
     */
    private function runSeeder(string $seeder): void
    {
        $this->app->make($seeder)->setContainer($this->app)->run();
    }

    private function pretendProduction(bool $seedDemo = false): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'seeding.demo' => $seedDemo]);
    }

    public function test_production_seed_creates_the_catalogs_only(): void
    {
        $this->pretendProduction();

        $this->runSeeder(DatabaseSeeder::class);

        // Catalogs are always seeded — a tenant cannot be provisioned without them.
        $this->assertGreaterThan(0, DB::table('permissions')->count());
        $this->assertGreaterThan(0, DB::table('notification_types')->count());

        // No demo academy, no demo accounts, not even the seeded platform admin.
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_the_demo_seeder_refuses_to_run_on_production(): void
    {
        $this->pretendProduction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('will not run on production');

        $this->runSeeder(AhmedTammamAcademySeeder::class);
    }

    public function test_seed_demo_flag_opts_production_back_in(): void
    {
        $this->pretendProduction(seedDemo: true);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('tenants', ['slug' => 'farag-physics']);
        $this->assertDatabaseHas('tenants', ['slug' => 'ahmedtammam.com']);
    }

    public function test_non_production_seeds_the_demo_academies(): void
    {
        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('tenants', ['slug' => 'farag-physics']);
        $this->assertDatabaseHas('users', ['phone' => '01000000000']); // platform admin
    }
}
