<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_runs_and_is_idempotent(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['phone' => '01000000009']);
        $this->assertDatabaseHas('users', ['phone' => '01500000001']);
        $this->assertDatabaseHas('users', ['phone' => '01281000001']); // academy 1, student 1
        $this->assertDatabaseCount('subscription_packages', 3);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('tenant_subscriptions', 2);
        $this->assertDatabaseCount('courses', 6);
        $this->assertDatabaseHas('tenants', ['slug' => 'ahmed-physics']);
        $this->assertDatabaseHas('tenants', ['slug' => 'mona-math']);

        // Re-run must not duplicate anything.
        $this->seed();

        $this->assertDatabaseCount('subscription_packages', 3);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('tenant_subscriptions', 2);
        $this->assertDatabaseCount('courses', 6);
    }
}
