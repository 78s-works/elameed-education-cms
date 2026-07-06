<?php

namespace App\Support\Rls;

use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security helper for tenant-scoped tables.
 *
 * Call TenantRls::enableFor('courses') from a migration AFTER the table (with
 * its `tenant_id` column) is created. This is the database-enforced backstop
 * behind the application-level BelongsToTenant scope — even a query that forgets
 * `where tenant_id = ?` cannot cross tenants. See 02_Architecture.md §4.2 and
 * 06_Engineering_Guide.md §8.
 *
 * The policy is FORCED so it also applies to the table owner (our app role owns
 * the tables), and reads the per-request GUC set by ResolveTenant. The GUC is
 * read with missing_ok = true and NULLIF'd so an unset/empty value yields NULL
 * (→ the predicate is false → zero rows): isolation fails CLOSED, never open.
 */
final class TenantRls
{
    public const POLICY = 'tenant_isolation';

    public static function enableFor(string $table, string $column = 'tenant_id'): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // RLS is Postgres-only; no-op elsewhere (e.g. sqlite test runs).
        }

        $var = config('tenancy.rls_session_var', 'app.tenant_id');
        $predicate = sprintf(
            "%s = NULLIF(current_setting(%s, true), '')::bigint",
            $column,
            self::quoteLiteral($var),
        );

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s FOR ALL USING (%s) WITH CHECK (%s)',
            self::POLICY,
            $table,
            $predicate,
            $predicate,
        ));
    }

    public static function disableFor(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::POLICY, $table));
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Quote a string as a Postgres SQL literal (the GUC name is config-controlled,
     * not user input, but we never interpolate a raw string into SQL).
     */
    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
