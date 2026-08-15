<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Package types (B27): teacher-managed, year-scoped content-package categories,
 * and the optional link from a content package to one of its year's types. Covers
 * CRUD, the same-year ceiling on linking, delete = null-out (packages survive),
 * tenant isolation, and (tenant, year, name) uniqueness.
 */
class PackageTypeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('2025 / 2026');
    }

    // --- helpers -----------------------------------------------------------

    private function makeYear(string $name, int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function teacher(?Tenant $tenant = null): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(?AcademicYear $year = null, string $slug = 'demo'): array
    {
        return ['X-Tenant' => $slug, 'X-Academic-Year' => ($year ?? $this->year)->uuid];
    }

    /** Create a type through the API; returns the decoded data array. */
    private function makeType(array $payload = [], ?AcademicYear $year = null): array
    {
        return $this->withHeaders($this->headers($year))
            ->postJson('/api/v1/teacher/package-types', array_merge(['name' => 'Term', 'channel' => 'hybrid'], $payload))
            ->assertStatus(201)->json('data');
    }

    private function makePackage(?int $typeId = null, ?AcademicYear $year = null): \Illuminate\Testing\TestResponse
    {
        $payload = ['name' => 'Package', 'access_mode' => 'both'];
        if ($typeId !== null) {
            $payload['package_type_id'] = $typeId;
        }

        return $this->withHeaders($this->headers($year))
            ->postJson('/api/v1/teacher/content-packages', $payload);
    }

    // --- CRUD --------------------------------------------------------------

    public function test_teacher_can_crud_a_package_type(): void
    {
        Sanctum::actingAs($this->teacher());

        $uuid = $this->withHeaders($this->headers())->postJson('/api/v1/teacher/package-types', [
            'name' => 'Revision', 'channel' => 'hybrid', 'sort_order' => 5,
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Revision')
            ->assertJsonPath('data.sort_order', 5)
            ->json('data.uuid');

        $this->assertDatabaseHas('package_types', [
            'name' => 'Revision', 'academic_year_id' => $this->year->id, 'tenant_id' => $this->tenant->id,
        ]);

        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/package-types/{$uuid}")
            ->assertOk()->assertJsonPath('data.name', 'Revision');

        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/package-types/{$uuid}", ['name' => 'Revisions'])
            ->assertOk()->assertJsonPath('data.name', 'Revisions');

        $this->withHeaders($this->headers())->getJson('/api/v1/teacher/package-types')
            ->assertOk()->assertJsonPath('data.0.name', 'Revisions');

        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/package-types/{$uuid}")->assertNoContent();
        $this->assertDatabaseMissing('package_types', ['uuid' => $uuid]);
    }

    public function test_types_are_isolated_per_academic_year(): void
    {
        Sanctum::actingAs($this->teacher());
        $other = $this->makeYear('Other', 10);
        $type = $this->makeType();

        $this->withHeaders($this->headers($other))->getJson('/api/v1/teacher/package-types')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($this->headers($other))->getJson("/api/v1/teacher/package-types/{$type['uuid']}")
            ->assertStatus(404);
    }

    // --- linking a package to a type ---------------------------------------

    public function test_package_can_link_a_type_from_its_own_year(): void
    {
        Sanctum::actingAs($this->teacher());
        $type = $this->makeType(['name' => 'Term 1']);

        $this->makePackage($type['id'])
            ->assertStatus(201)
            ->assertJsonPath('data.type.id', $type['id'])
            ->assertJsonPath('data.type.uuid', $type['uuid'])
            ->assertJsonPath('data.type.name', 'Term 1');
    }

    public function test_a_year_b_type_cannot_be_linked_to_a_year_a_package(): void
    {
        Sanctum::actingAs($this->teacher());
        $yearB = $this->makeYear('Year B', 10);
        $typeB = $this->makeType(['name' => 'Foreign'], $yearB);   // type lives in year B

        // Package is created in year A ($this->year); referencing the year-B type is rejected.
        $this->makePackage($typeB['id'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['package_type_id']]]);
    }

    public function test_deleting_a_type_nulls_its_packages_but_keeps_them(): void
    {
        Sanctum::actingAs($this->teacher());
        $type = $this->makeType(['name' => 'Doomed']);
        $packageId = $this->makePackage($type['id'])->assertStatus(201)->json('data.id');

        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/package-types/{$type['uuid']}")
            ->assertNoContent();

        // Package survives, its type link is nulled.
        $this->assertDatabaseHas('packages', ['id' => $packageId, 'package_type_id' => null]);
        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/content-packages/{$packageId}")
            ->assertOk()->assertJsonMissingPath('data.type');
    }

    // --- guards ------------------------------------------------------------

    public function test_name_is_unique_per_tenant_and_year(): void
    {
        Sanctum::actingAs($this->teacher());
        $this->makeType(['name' => 'Dup']);

        // Same name, same year → rejected.
        $this->withHeaders($this->headers())->postJson('/api/v1/teacher/package-types', ['name' => 'Dup', 'channel' => 'hybrid'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name']]]);

        // Same name in a different year → allowed (year is part of the key).
        $other = $this->makeYear('Other', 10);
        $this->withHeaders($this->headers($other))->postJson('/api/v1/teacher/package-types', ['name' => 'Dup', 'channel' => 'hybrid'])
            ->assertStatus(201);
    }

    public function test_types_are_isolated_per_tenant(): void
    {
        $otherTenant = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'status' => TenantStatus::Active]);
        Sanctum::actingAs($this->teacher());
        $mine = $this->makeType(['name' => 'Mine']);

        // A rival-tenant teacher, scoped to a rival year, cannot see my type.
        $rivalYear = new AcademicYear(['name' => 'Rival Year']);
        $rivalYear->tenant_id = $otherTenant->id;
        $rivalYear->save();

        Sanctum::actingAs($this->teacher($otherTenant));
        $this->withHeaders($this->headers($rivalYear, 'rival'))
            ->getJson("/api/v1/teacher/package-types/{$mine['uuid']}")
            ->assertStatus(404);
    }
}
