<?php

namespace Tests\Feature\Security;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Structural guards for the authorization layer (M20).
 *
 * The other tests prove behaviour for the code that exists today. These prove
 * properties of the codebase itself, so the next person cannot reintroduce the
 * old shape by accident:
 *
 *   1. No staff route is left ungated.
 *   2. Authority is never decided from the membership KIND column.
 *   3. Every catalog key is actually enforced somewhere.
 */
class AuthorizationGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Staff routes that legitimately carry no `can:` gate, each with the reason.
     * Anything else showing up here is a hole, not a new exception to add
     * casually.
     */
    private const UNGATED_BY_DESIGN = [
        // Request context every panel screen needs; gated by membership kind
        // because a staff member who cannot list the years cannot use any screen.
        'api/v1/teacher/academic-years' => 'year selector context',
        'api/v1/teacher/academic-years/{academicYear}' => 'year selector context',
    ];

    public function test_every_teacher_route_is_gated_by_a_permission(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/v1/teacher/')) {
                continue;
            }

            if (array_key_exists($route->uri(), self::UNGATED_BY_DESIGN)) {
                continue;
            }

            if ($this->permissionOf($route) === null) {
                $ungated[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $ungated, 'These staff routes enforce no permission');
    }

    public function test_no_route_is_gated_by_the_retired_screen_level_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertStringStartsNotWith(
                    'permission:',
                    is_string($middleware) ? $middleware : '',
                    'Route '.$route->uri().' still uses the retired screen-level gate'
                );
            }
        }
    }

    public function test_every_gate_names_a_key_that_exists_in_the_catalog(): void
    {
        $catalog = PermissionEnum::values();
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            $key = $this->permissionOf($route);

            if ($key !== null && ! in_array($key, $catalog, true)) {
                $unknown[] = $route->uri().' → '.$key;
            }
        }

        $this->assertSame([], $unknown, 'Routes gated on keys the catalog does not define');
    }

    public function test_every_catalog_key_is_enforced_by_at_least_one_route(): void
    {
        $enforced = [];

        foreach (Route::getRoutes() as $route) {
            $key = $this->permissionOf($route);

            if ($key !== null) {
                $enforced[$key] = true;
            }
        }

        // A key nothing checks is a false promise in the picker: the teacher reads
        // it as a restriction, and it restricts nothing.
        $orphans = array_values(array_diff(PermissionEnum::values(), array_keys($enforced)));

        $this->assertSame([], $orphans, 'Catalog keys no route enforces');
    }

    public function test_the_membership_kind_column_is_never_used_to_decide_authority(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(base_path('app')) as $file) {
            $contents = file_get_contents($file);

            // The column may be read for classification (queries, reporting, the
            // panel a member is sent to). What it may NOT do is answer "may they?"
            // — that is the implicit authority this milestone removed.
            foreach (['TenantUserRole::Teacher' => 'role === TenantUserRole::Teacher'] as $needle => $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' → '.$pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Authority must come from a role, not from a comparison against the membership kind'
        );
    }

    /** The permission key a route is gated on, if any. */
    private function permissionOf(RouteInstance $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // Laravel's `can:` alias resolves to the Authorize middleware class.
            if (Str::startsWith($middleware, ['can:', 'Illuminate\Auth\Middleware\Authorize:'])) {
                return Str::after($middleware, ':');
            }
        }

        return null;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
