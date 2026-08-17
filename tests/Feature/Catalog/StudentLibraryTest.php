<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VD F1/F13 — the student library (/me/lessons, /me/packages = bought) and the
 * catalogue's exclude_owned filter (Explore = not-yet-bought).
 */
class StudentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function student(): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function lesson(bool $purchasable = true): Lesson
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->first();
        if ($year === null) {
            $year = new AcademicYear(['name' => '2026', 'sort_order' => 0]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
        }

        $l = new Lesson([
            'title' => 'Lesson',
            'visibility' => ContentVisibility::Visible->value,
            'is_purchasable' => $purchasable,
            'price_minor' => 5000,
        ]);
        $l->tenant_id = $this->tenant->id;
        $l->academic_year_id = $year->id;
        $l->save();

        return $l;
    }

    private function package(): Package
    {
        $year = new AcademicYear(['name' => '2026', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        $p = new Package([
            'name' => 'Bundle',
            'is_purchasable' => true,
            'price_minor' => 9000,
            'access_mode' => 'online',
        ]);
        $p->tenant_id = $this->tenant->id;
        $p->academic_year_id = $year->id;
        $p->save();

        return $p;
    }

    public function test_me_lessons_returns_bought_lessons(): void
    {
        $student = $this->student();
        $lesson = $this->lesson();
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/lessons')
            ->assertOk()->json('data');

        $row = collect($data)->firstWhere('id', $lesson->id);
        $this->assertNotNull($row);
        $this->assertSame($lesson->title, $row['title']);
    }

    public function test_me_packages_returns_bought_packages(): void
    {
        $student = $this->student();
        $package = $this->package();
        // A package buy fans out into per-lesson grants that carry the source
        // package_id as provenance; /me/packages derives ownership from that.
        $lesson = $this->lesson();
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase, $package->id);

        Sanctum::actingAs($student);
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/me/packages')
            ->assertOk()->json('data');

        $this->assertTrue(collect($data)->contains('uuid', $package->uuid));
    }

    public function test_catalogue_exclude_owned_drops_bought_lessons(): void
    {
        $student = $this->student();
        $owned = $this->lesson();
        $notOwned = $this->lesson();
        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $owned, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $ids = collect(
            $this->withHeader('X-Tenant', 'demo')
                ->getJson('/api/v1/catalogue?view=lessons&exclude_owned=1')
                ->assertOk()->json('data')
        )->pluck('id');

        $this->assertFalse($ids->contains($owned->id), 'owned lesson must be excluded');
        $this->assertTrue($ids->contains($notOwned->id), 'unowned lesson must remain');
    }
}
