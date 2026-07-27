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
        $this->assertDatabaseHas('users', ['phone' => '0101000001']);   // farag-physics teacher/owner
        $this->assertDatabaseHas('users', ['phone' => '0101000101']);   // farag-physics, student 1
        // Physical row counts (assertDatabaseCount counts soft-deleted rows too):
        //   4 packages (incl. retired legacy-basic), 3 tenants (2 active + 1 closed),
        //   3 subscriptions (2 active + 1 canceled), 10 courses (5 per academy).
        $this->assertDatabaseCount('subscription_packages', 4);
        $this->assertDatabaseCount('tenants', 3);
        $this->assertDatabaseCount('tenant_subscriptions', 3);
        $this->assertDatabaseCount('courses', 10);
        $this->assertDatabaseHas('tenants', ['slug' => 'farag-physics']);
        $this->assertDatabaseHas('tenants', ['slug' => 'sara-chemistry']);

        // Re-run must not duplicate anything.
        $this->seed();

        $this->assertDatabaseCount('subscription_packages', 4);
        $this->assertDatabaseCount('tenants', 3);
        $this->assertDatabaseCount('tenant_subscriptions', 3);
        $this->assertDatabaseCount('courses', 10);
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
