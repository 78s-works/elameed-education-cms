<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\PackageType;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recursive content packages (VD change set §7/§8, doc 13 Phase 5): year-scoped
 * package CRUD, attaching lessons + sub-packages with the cycle / subset /
 * same-year guards, reordering, and the delete/auto-detach rules. Also asserts
 * the units/bundles retirement (tables dropped, packages present).
 */
class ContentPackageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('2025 / 2026');
        $this->typeId = $this->makeType();
    }

    /** A package now requires a type (B27); every package this suite creates uses this default. */
    private function makeType(?AcademicYear $year = null): int
    {
        $type = new PackageType(['name' => 'Default', 'channel' => 'hybrid', 'buy_alone' => false, 'sort_order' => 0]);
        $type->tenant_id = $this->tenant->id;
        $type->academic_year_id = ($year ?? $this->year)->id;
        $type->save();

        return $type->id;
    }

    // --- helpers -----------------------------------------------------------

    private function makeYear(string $name, int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(?AcademicYear $year = null): array
    {
        return ['X-Tenant' => 'demo', 'X-Academic-Year' => ($year ?? $this->year)->uuid];
    }

    /** Create a lesson through the API; returns its (integer) id. */
    private function makeLesson(string $accessMode = 'both', string $name = 'Lesson', ?AcademicYear $year = null): int
    {
        return $this->withHeaders($this->headers($year))
            ->postJson('/api/v1/teacher/lessons', ['name' => $name, 'access_mode' => $accessMode])
            ->assertStatus(201)->json('data.id');
    }

    /** Create a package through the API; returns its (integer) id. */
    private function makePackage(string $accessMode = 'both', string $name = 'Package', ?AcademicYear $year = null): int
    {
        return $this->withHeaders($this->headers($year))
            ->postJson('/api/v1/teacher/content-packages', ['name' => $name, 'access_mode' => $accessMode, 'package_type_id' => $this->typeId])
            ->assertStatus(201)->json('data.id');
    }

    private function attach(int $package, string $type, int $itemId)
    {
        return $this->withHeaders($this->headers())
            ->postJson("/api/v1/teacher/content-packages/{$package}/items", ['item_type' => $type, 'item_id' => $itemId]);
    }

    // --- CRUD --------------------------------------------------------------

    public function test_teacher_can_crud_a_package(): void
    {
        Sanctum::actingAs($this->teacher());

        $id = $this->withHeaders($this->headers())->postJson('/api/v1/teacher/content-packages', [
            'name' => 'Term 1', 'access_mode' => 'both', 'price_minor' => 20000, 'currency' => 'EGP', 'is_purchasable' => true,
            'package_type_id' => $this->typeId,
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Term 1')
            ->assertJsonPath('data.access_mode', 'both')
            ->assertJsonPath('data.is_purchasable', true)
            ->assertJsonPath('data.items', [])
            ->json('data.id');

        $this->assertDatabaseHas('packages', ['id' => $id, 'name' => 'Term 1', 'access_mode' => 'both', 'academic_year_id' => $this->year->id]);

        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/content-packages/{$id}")
            ->assertOk()->assertJsonPath('data.name', 'Term 1');

        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/content-packages/{$id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->withHeaders($this->headers())->getJson('/api/v1/teacher/content-packages')
            ->assertOk()->assertJsonPath('data.0.id', $id);

        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/content-packages/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('packages', ['id' => $id]);
    }

    public function test_packages_are_isolated_per_academic_year(): void
    {
        Sanctum::actingAs($this->teacher());
        $other = $this->makeYear('Other', 10);
        $id = $this->makePackage();

        $this->withHeaders($this->headers($other))->getJson('/api/v1/teacher/content-packages')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($this->headers($other))->getJson("/api/v1/teacher/content-packages/{$id}")->assertStatus(404);
    }

    // --- attach: lesson + sub-package --------------------------------------

    public function test_attach_a_lesson_and_a_sub_package(): void
    {
        Sanctum::actingAs($this->teacher());
        $parent = $this->makePackage('both', 'Parent');
        $lesson = $this->makeLesson('both', 'L1');
        $child = $this->makePackage('both', 'Child');

        $this->attach($parent, 'lesson', $lesson)->assertStatus(201)
            ->assertJsonPath('data.item_type', 'lesson')
            ->assertJsonPath('data.item.type', 'lesson')
            ->assertJsonPath('data.item.id', $lesson);

        $this->attach($parent, 'package', $child)->assertStatus(201)
            ->assertJsonPath('data.item_type', 'package');

        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/content-packages/{$parent}")
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.item_id', $lesson)
            ->assertJsonPath('data.items.1.item_id', $child);

        // Duplicate attach is rejected.
        $this->attach($parent, 'lesson', $lesson)->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    // --- guards ------------------------------------------------------------

    public function test_cycle_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $a = $this->makePackage('both', 'A');
        $b = $this->makePackage('both', 'B');

        $this->attach($a, 'package', $b)->assertStatus(201);          // A ⊃ B
        $this->attach($b, 'package', $a)->assertStatus(422)           // B ⊃ A would loop
            ->assertJsonStructure(['error' => ['details' => ['item_id']]]);
        $this->attach($a, 'package', $a)->assertStatus(422);          // self-cycle
    }

    public function test_transitive_cycle_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $a = $this->makePackage('both', 'A');
        $b = $this->makePackage('both', 'B');
        $c = $this->makePackage('both', 'C');

        $this->attach($a, 'package', $b)->assertStatus(201);          // A ⊃ B
        $this->attach($b, 'package', $c)->assertStatus(201);          // B ⊃ C

        // A ⊃ B ⊃ C already; attaching A under C closes the loop (C ⊃ … ⊃ A).
        $this->attach($c, 'package', $a)->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['item_id']]]);

        // The mid-chain node likewise cannot swallow its ancestor.
        $this->attach($c, 'package', $b)->assertStatus(422);          // C ⊃ B would loop
    }

    public function test_subset_ceiling_is_enforced(): void
    {
        Sanctum::actingAs($this->teacher());
        $online = $this->makePackage('online', 'Online pkg');

        // center ⊄ online, both ⊄ online → 422; online ⊆ online → ok.
        $this->attach($online, 'lesson', $this->makeLesson('center'))->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['item_id']]]);
        $this->attach($online, 'lesson', $this->makeLesson('both'))->assertStatus(422);
        $this->attach($online, 'lesson', $this->makeLesson('online'))->assertStatus(201);

        // A `both` package admits any child.
        $both = $this->makePackage('both', 'Both pkg');
        $this->attach($both, 'lesson', $this->makeLesson('center'))->assertStatus(201);
    }

    public function test_cross_year_attach_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());
        $other = $this->makeYear('Other', 10);

        $lessonOtherYear = $this->makeLesson('both', 'Far', $other);
        $package = $this->makePackage('both', 'Here');   // in $this->year

        $this->attach($package, 'lesson', $lessonOtherYear)->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['item_id']]]);
    }

    public function test_items_can_be_reordered(): void
    {
        Sanctum::actingAs($this->teacher());
        $pkg = $this->makePackage('both');
        $i1 = $this->attach($pkg, 'lesson', $this->makeLesson('both', 'L1'))->json('data.id');
        $i2 = $this->attach($pkg, 'lesson', $this->makeLesson('both', 'L2'))->json('data.id');
        $i3 = $this->attach($pkg, 'lesson', $this->makeLesson('both', 'L3'))->json('data.id');

        $this->withHeaders($this->headers())->putJson("/api/v1/teacher/content-packages/{$pkg}/items/reorder", [
            'order' => [$i3, $i1, $i2],
        ])->assertOk()
            ->assertJsonPath('data.0.id', $i3)
            ->assertJsonPath('data.1.id', $i1)
            ->assertJsonPath('data.2.id', $i2);
    }

    // --- delete semantics --------------------------------------------------

    public function test_deleting_a_package_keeps_its_member_lessons(): void
    {
        Sanctum::actingAs($this->teacher());
        $pkg = $this->makePackage('both');
        $lesson = $this->makeLesson('both', 'Kept');
        $this->attach($pkg, 'lesson', $lesson)->assertStatus(201);

        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/content-packages/{$pkg}")->assertNoContent();

        // Lesson survives; the pivot row is gone.
        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/lessons/{$lesson}")->assertOk();
        $this->assertDatabaseMissing('package_items', ['package_id' => $pkg]);
    }

    public function test_deleting_a_lesson_auto_detaches_it_from_packages(): void
    {
        Sanctum::actingAs($this->teacher());
        $pkg = $this->makePackage('both');
        $lesson = $this->makeLesson('both', 'Doomed');
        $this->attach($pkg, 'lesson', $lesson)->assertStatus(201);
        $this->assertDatabaseHas('package_items', ['package_id' => $pkg, 'item_type' => 'lesson', 'item_id' => $lesson]);

        $this->withHeaders($this->headers())->deleteJson("/api/v1/teacher/lessons/{$lesson}")->assertNoContent();

        $this->assertDatabaseMissing('package_items', ['item_type' => 'lesson', 'item_id' => $lesson]);
        $this->withHeaders($this->headers())->getJson("/api/v1/teacher/content-packages/{$pkg}")
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    // --- retirement (migration mapping) ------------------------------------

    public function test_units_and_bundles_are_retired(): void
    {
        // The retirement migration dropped the old grouping tables and created the
        // recursive-package ones. (Row-level mapping is exercised on real data in
        // the migration itself; the test DB migrates fresh from empty tables.)
        $this->assertFalse(Schema::hasTable('units'), 'units table should be dropped');
        $this->assertFalse(Schema::hasTable('bundles'), 'bundles table should be dropped');
        $this->assertFalse(Schema::hasTable('bundle_items'), 'bundle_items table should be dropped');
        $this->assertFalse(Schema::hasTable('unit_dependencies'), 'unit_dependencies table should be dropped');

        $this->assertTrue(Schema::hasTable('packages'));
        $this->assertTrue(Schema::hasTable('package_items'));

        // unit_id columns retired too (VD §7) — no code reads them any more.
        $this->assertFalse(Schema::hasColumn('lessons', 'unit_id'));
        $this->assertFalse(Schema::hasColumn('enrollments', 'unit_id'));
    }
}
