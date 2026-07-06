<?php

namespace App\Modules\Tenancy\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Binds (and resets) the Postgres session GUC that Row-Level Security policies
 * read — `app.tenant_id`. This is the DB-enforced half of tenant isolation.
 *
 * THE FOOTGUN (02_Architecture.md §4.2, 06_Engineering_Guide.md §8): the GUC
 * lives on a pooled/persistent connection. Under Octane, persistent pools, or
 * queue workers a connection is reused across requests; if the value is not
 * re-established every request, one tenant's id bleeds into the next → a
 * cross-tenant leak. So ResolveTenant calls bind()/reset() at the START of
 * every request (not only on teardown), and reset() again on terminate.
 *
 * set_config(var, value, is_local => false) sets it at session scope so it
 * survives across statements within the request but is cleared by reset().
 */
class TenantSession
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function bind(int $tenantId): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return; // non-pgsql (e.g. sqlite test run): RLS not in play.
        }

        $connection->statement(
            'select set_config(?, ?, false)',
            [$this->var(), (string) $tenantId],
        );
    }

    public function reset(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        // Empty string, not NULL: the RLS predicate NULLIF's it back to NULL so
        // the isolation check fails closed (zero rows) rather than erroring.
        $connection->statement('select set_config(?, ?, false)', [$this->var(), '']);
    }

    private function connection(): ?ConnectionInterface
    {
        $connection = $this->db->connection();

        return $connection->getDriverName() === 'pgsql' ? $connection : null;
    }

    private function var(): string
    {
        return (string) config('tenancy.rls_session_var', 'app.tenant_id');
    }
}
