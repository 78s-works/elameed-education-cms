<?php

namespace Tests;

use Database\Seeders\RbacTestSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The authorization layer is data, not code (M20): a tenant gets its roles by
     * copying the platform templates, and a membership gets its baseline role from
     * those copies. With an empty catalog every tenant created in a test would
     * have no roles to hand out, so creating any member would fail loudly.
     *
     * Seeded once per test-database refresh (outside the per-test transaction),
     * so it costs one insert pass for the whole suite.
     */
    protected $seed = true;

    protected $seeder = RbacTestSeeder::class;
}
