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

        // Lean, year-partitioned academy: one tenant, 3 academic years, and per
        // year a package-type + 3 lessons + 1 package + 2 students.
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('academic_years', 3);
        $this->assertDatabaseCount('package_types', 3);
        $this->assertDatabaseCount('packages', 3);
        $this->assertDatabaseCount('lessons', 9);
        // 1 teacher + (3 years × 2) students, tenant-scoped.
        $this->assertDatabaseCount('student_profiles', 6);

        // Every content row is stamped with an academic year (year-dependent seed).
        $this->assertDatabaseMissing('lessons', ['academic_year_id' => null]);
        $this->assertDatabaseMissing('packages', ['academic_year_id' => null]);
        $this->assertDatabaseMissing('student_profiles', ['academic_year_id' => null]);

        // Re-run must not duplicate anything (academy is skipped when present).
        $this->seed();

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('academic_years', 3);
        $this->assertDatabaseCount('lessons', 9);
        $this->assertDatabaseCount('packages', 3);
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
