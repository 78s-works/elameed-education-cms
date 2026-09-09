<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Gate for seeders that create demo data (fake academies, students, orders,
 * payments). Ruled 8 Sep: production holds real data only — demos live on the
 * test environment. Outside production these seeders run as before; on
 * production they need an explicit `SEED_DEMO=true` opt-in.
 */
trait GuardsDemoSeeding
{
    protected function demoSeedingAllowed(): bool
    {
        return ! app()->environment('production') || (bool) config('seeding.demo', false);
    }

    /**
     * For a demo seeder invoked directly (`db:seed --class=...`): refuse rather
     * than quietly write demo rows into a production database.
     *
     * @throws RuntimeException
     */
    protected function abortUnlessDemoSeedingAllowed(): void
    {
        if ($this->demoSeedingAllowed()) {
            return;
        }

        throw new RuntimeException(
            static::class.' creates demo data and will not run on production. '
            .'Seed it on the test environment, or set SEED_DEMO=true to opt in explicitly.'
        );
    }
}
