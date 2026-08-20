<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_runs_and_is_idempotent(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['phone' => '01000000000']);  // platform admin
        $this->assertDatabaseHas('users', ['phone' => '0101000001']);   // farag-physics teacher
        $this->assertDatabaseHas('users', ['phone' => '0101000101']);   // year 1, online student
        $this->assertDatabaseHas('tenants', ['slug' => 'farag-physics']);

        // Counts are asserted PER TENANT: DatabaseSeeder also runs the full
        // ahmedtammam academy plus one soft-deleted archive tenant, so global
        // counts say nothing about the lean academy's own contract.
        $lean = (int) DB::table('tenants')->where('slug', 'farag-physics')->value('id');

        // Lean, year-partitioned academy: 3 academic years, and per year a
        // package-type + 3 lessons + 1 package + 2 students.
        $this->assertSame(3, $this->countFor('academic_years', $lean));
        $this->assertSame(3, $this->countFor('package_types', $lean));
        $this->assertSame(3, $this->countFor('packages', $lean));
        $this->assertSame(9, $this->countFor('lessons', $lean));
        // (3 years × 2) students, tenant-scoped.
        $this->assertSame(6, $this->countFor('student_profiles', $lean));

        // Every content row is stamped with an academic year (year-dependent seed)
        // — across ALL academies, not just the lean one.
        $this->assertDatabaseMissing('lessons', ['academic_year_id' => null]);
        $this->assertDatabaseMissing('packages', ['academic_year_id' => null]);
        $this->assertDatabaseMissing('student_profiles', ['academic_year_id' => null]);

        // The archive tenant exists only as a soft-deleted row (the platform-admin
        // trashed branch), so it must never show up in a normal tenant query.
        $this->assertDatabaseHas('tenants', ['slug' => 'closed-academy']);
        $this->assertNotNull(DB::table('tenants')->where('slug', 'closed-academy')->value('deleted_at'));

        $tenantsBefore = DB::table('tenants')->count();

        // Re-run must not duplicate anything (each academy is skipped when present).
        $this->seed();

        $this->assertSame($tenantsBefore, DB::table('tenants')->count());
        $this->assertSame(3, $this->countFor('academic_years', $lean));
        $this->assertSame(9, $this->countFor('lessons', $lean));
        $this->assertSame(3, $this->countFor('packages', $lean));
    }

    /** Row count in $table belonging to one tenant. */
    private function countFor(string $table, int $tenantId): int
    {
        return (int) DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    /**
     * Regression: seeding must NOT implicitly commit an ambient transaction.
     *
     * The seeder used to clear tables with TRUNCATE, which is DDL and implicitly
     * COMMITs on MySQL — silently ending the RefreshDatabase test transaction and
     * leaving every later ROLLBACK-to-savepoint (e.g. a validation failure inside a
     * DB::transaction) throwing "SAVEPOINT does not exist" (a 500). We are inside a
     * RefreshDatabase transaction here, so after seeding a savepoint round-trip must
     * still succeed — proving the outer transaction survived.
     */
    public function test_seeding_does_not_break_the_surrounding_transaction(): void
    {
        $this->assertGreaterThan(0, DB::transactionLevel(), 'Expected to be inside the RefreshDatabase transaction.');

        $this->seed();

        $pdo = DB::connection()->getPdo();
        $pdo->exec('SAVEPOINT _txn_probe');
        $pdo->exec('ROLLBACK TO SAVEPOINT _txn_probe');
        $pdo->exec('RELEASE SAVEPOINT _txn_probe');

        // If we got here without a PDOException, the transaction is intact.
        $this->assertTrue(true);
    }
}
