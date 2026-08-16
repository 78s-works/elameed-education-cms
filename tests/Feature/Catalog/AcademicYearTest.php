<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Probe route to exercise the `academic-year` middleware over HTTP: it
        // sits behind the same `tenant` group as real routes and echoes the
        // resolved context id. The CRUD routes themselves are deliberately NOT
        // behind this middleware, so this is the only way to test it end-to-end.
        Route::middleware(['tenant', 'academic-year'])->get(
            '/api/_probe/academic-year',
            fn (AcademicYearContext $context) => response()->json(['academic_year_id' => $context->id()]),
        );
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function makeTeacher(Tenant $tenant): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function makeStudent(Tenant $tenant): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Create a year directly for a tenant (no request context in tests). */
    private function makeYear(Tenant $tenant, string $name = 'Year', int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $tenant->id;
        $year->save();

        return $year;
    }

    public function test_teacher_can_crud_academic_years(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        // Create → 201, id is the uuid (not the bigint).
        $created = $this->withHeaders($h)->postJson('/api/v1/teacher/academic-years', [
            'name' => '2025 / 2026',
            'sort_order' => 3,
        ])->assertStatus(201)
            ->assertJsonPath('data.name', '2025 / 2026')
            ->assertJsonPath('data.sort_order', 3)
            ->json('data');

        $uuid = $created['id'];
        $this->assertTrue(Str::isUuid($uuid));

        // List → contains the new year.
        $this->withHeaders($h)->getJson('/api/v1/teacher/academic-years')
            ->assertOk()
            ->assertJsonPath('data.0.id', $uuid)
            ->assertJsonPath('data.0.name', '2025 / 2026');

        // Show by uuid.
        $this->withHeaders($h)->getJson("/api/v1/teacher/academic-years/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.name', '2025 / 2026');

        // Update.
        $this->withHeaders($h)->putJson("/api/v1/teacher/academic-years/{$uuid}", [
            'name' => 'Renamed Year',
            'sort_order' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed Year')
            ->assertJsonPath('data.sort_order', 1);
    }

    public function test_index_is_ordered_by_sort_order(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));

        $this->makeYear($tenant, 'Third', 30);
        $this->makeYear($tenant, 'First', 10);
        $this->makeYear($tenant, 'Second', 20);

        $names = $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/teacher/academic-years')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['First', 'Second', 'Third'], $names);
    }

    public function test_name_is_required_on_create(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/teacher/academic-years', ['sort_order' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    /**
     * Cross-tenant isolation — a teacher of tenant A cannot see or reach tenant
     * B's academic year even with its exact uuid (binding is tenant-scoped → 404).
     */
    public function test_academic_years_are_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');
        $yearB = $this->makeYear($tenantB, 'B Only');

        Sanctum::actingAs($this->makeTeacher($tenantA));
        $h = ['X-Tenant' => 'alpha'];

        // A's list is empty (B's year excluded by the tenant scope).
        $this->withHeaders($h)->getJson('/api/v1/teacher/academic-years')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A cannot show / update / delete B's year by uuid → 404.
        $this->withHeaders($h)->getJson("/api/v1/teacher/academic-years/{$yearB->uuid}")->assertStatus(404);
        $this->withHeaders($h)->putJson("/api/v1/teacher/academic-years/{$yearB->uuid}", ['name' => 'hack'])->assertStatus(404);
        $this->withHeaders($h)->deleteJson("/api/v1/teacher/academic-years/{$yearB->uuid}", ['confirm_name' => 'B Only'])->assertStatus(404);

        $this->assertSame('B Only', $yearB->fresh()->name);
    }

    public function test_delete_requires_matching_confirm_name(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        $year = $this->makeYear($tenant, 'Deletable');

        // Missing confirm_name → 422, row survives.
        $this->withHeaders($h)->deleteJson("/api/v1/teacher/academic-years/{$year->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        // Wrong confirm_name → 422, row survives.
        $this->withHeaders($h)->deleteJson("/api/v1/teacher/academic-years/{$year->uuid}", ['confirm_name' => 'wrong'])
            ->assertStatus(422);

        $this->assertDatabaseHas('academic_years', ['uuid' => $year->uuid]);

        // Exact confirm_name → 204, row gone.
        $this->withHeaders($h)->deleteJson("/api/v1/teacher/academic-years/{$year->uuid}", ['confirm_name' => 'Deletable'])
            ->assertNoContent();

        $this->assertDatabaseMissing('academic_years', ['uuid' => $year->uuid]);
    }

    public function test_student_cannot_manage_academic_years(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeStudent($tenant));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/teacher/academic-years', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    // --- ResolveAcademicYear middleware ------------------------------------

    public function test_middleware_returns_422_when_header_absent(): void
    {
        $this->makeTenant('demo');

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/_probe/academic-year')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_middleware_returns_403_for_unknown_or_foreign_year(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');
        $yearB = $this->makeYear($tenantB, 'B Year');

        // Unknown uuid.
        $this->withHeaders(['X-Tenant' => 'alpha', 'X-Academic-Year' => (string) Str::uuid()])
            ->getJson('/api/_probe/academic-year')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        // A real uuid, but it belongs to tenant B → forbidden on tenant A.
        $this->withHeaders(['X-Tenant' => 'alpha', 'X-Academic-Year' => $yearB->uuid])
            ->getJson('/api/_probe/academic-year')
            ->assertStatus(403);
    }

    public function test_middleware_sets_context_for_a_valid_year(): void
    {
        $tenant = $this->makeTenant('demo');
        $year = $this->makeYear($tenant, 'Current');

        $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $year->uuid])
            ->getJson('/api/_probe/academic-year')
            ->assertOk()
            ->assertJsonPath('academic_year_id', $year->id);
    }

    public function test_student_is_pinned_to_their_profile_year_ignoring_header(): void
    {
        $tenant = $this->makeTenant('demo');
        $yearA = $this->makeYear($tenant, 'Grade A', 0);
        $yearB = $this->makeYear($tenant, 'Grade B', 1);

        $student = $this->makeStudent($tenant);
        $profile = new StudentProfile(['academic_year_id' => $yearA->id]);
        $profile->tenant_id = $tenant->id;
        $profile->user_id = $student->id;
        $profile->save();

        Sanctum::actingAs($student);

        // No header at all → the student is still scoped to their own year (server-
        // authoritative), where a teacher/assistant would 422 on the strict route.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/_probe/academic-year')
            ->assertOk()
            ->assertJsonPath('academic_year_id', $yearA->id);

        // A forged header for another year is ignored — the profile year wins.
        $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $yearB->uuid])
            ->getJson('/api/_probe/academic-year')
            ->assertOk()
            ->assertJsonPath('academic_year_id', $yearA->id);
    }
}
