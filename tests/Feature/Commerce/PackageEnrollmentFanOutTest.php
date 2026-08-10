<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * B15 / VD LP-D2: buying a recursive package fans out depth-first into a
 * per-lesson enrollment for every descendant lesson (nested packages included),
 * tagged with the source package, idempotent on re-buy and across overlaps. These
 * exercise EnrollmentService::grantPackage directly (the checkout wiring is
 * covered end-to-end in CheckoutTest).
 */
class PackageEnrollmentFanOutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->userId = User::factory()->create()->id;
        $year = new AcademicYear(['name' => '2025 / 2026', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();
        $this->year = $year;
    }

    // --- helpers -----------------------------------------------------------

    private function service(): EnrollmentService
    {
        return app(EnrollmentService::class);
    }

    private function lesson(string $title, ?int $availabilityDays = null): Lesson
    {
        $lesson = new Lesson(['title' => $title, 'access_mode' => 'both', 'availability_days' => $availabilityDays]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year->id;
        $lesson->save();

        return $lesson;
    }

    private function package(string $name): Package
    {
        $package = new Package(['name' => $name, 'access_mode' => 'both']);
        $package->tenant_id = $this->tenant->id;
        $package->academic_year_id = $this->year->id;
        $package->save();

        return $package;
    }

    private function attach(Package $package, string $type, int $itemId): void
    {
        $item = new PackageItem(['package_id' => $package->id, 'item_type' => $type, 'item_id' => $itemId, 'sort_order' => 0]);
        $item->tenant_id = $this->tenant->id;
        $item->save();
    }

    private function grant(Package $package): int
    {
        return $this->service()
            ->grantPackage($this->tenant->id, $this->userId, $package, EnrollmentSource::Purchase)
            ->count();
    }

    private function lessonEnrollments(): \Illuminate\Support\Collection
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->userId)
            ->whereNotNull('lesson_id')
            ->get();
    }

    // --- fan-out -----------------------------------------------------------

    public function test_package_buy_fans_out_recursively_into_per_lesson_enrollments(): void
    {
        // A ⊃ (L1, B); B ⊃ (L2, C); C ⊃ L3  — three lessons, nested three deep.
        $a = $this->package('A');
        $b = $this->package('B');
        $c = $this->package('C');
        $l1 = $this->lesson('L1');
        $l2 = $this->lesson('L2');
        $l3 = $this->lesson('L3');

        $this->attach($a, PackageItem::TYPE_LESSON, $l1->id);
        $this->attach($a, PackageItem::TYPE_PACKAGE, $b->id);
        $this->attach($b, PackageItem::TYPE_LESSON, $l2->id);
        $this->attach($b, PackageItem::TYPE_PACKAGE, $c->id);
        $this->attach($c, PackageItem::TYPE_LESSON, $l3->id);

        $this->assertSame(3, $this->grant($a));

        $rows = $this->lessonEnrollments();
        $this->assertEqualsCanonicalizing(
            [$l1->id, $l2->id, $l3->id],
            $rows->pluck('lesson_id')->all(),
        );
        // Every fanned-out row records the purchased package as provenance.
        $this->assertTrue($rows->every(fn (Enrollment $e) => (int) $e->package_id === $a->id));
        $this->assertTrue($rows->every(fn (Enrollment $e) => $e->source === EnrollmentSource::Purchase));
    }

    public function test_fan_out_is_idempotent_on_rebuy(): void
    {
        $a = $this->package('A');
        $l1 = $this->lesson('L1');
        $l2 = $this->lesson('L2');
        $this->attach($a, PackageItem::TYPE_LESSON, $l1->id);
        $this->attach($a, PackageItem::TYPE_LESSON, $l2->id);

        $this->assertSame(2, $this->grant($a));
        $this->assertSame(2, $this->grant($a)); // re-buy returns the same grants

        $this->assertSame(2, $this->lessonEnrollments()->count());
    }

    public function test_overlap_with_direct_grant_and_another_package_is_not_duplicated(): void
    {
        $shared = $this->lesson('Shared');
        $onlyA = $this->lesson('OnlyA');
        $onlyB = $this->lesson('OnlyB');

        // Student already bought the shared lesson on its own (no package provenance).
        $this->service()->grantLesson($this->tenant->id, $this->userId, $shared, EnrollmentSource::Purchase);

        $a = $this->package('A');
        $this->attach($a, PackageItem::TYPE_LESSON, $shared->id);
        $this->attach($a, PackageItem::TYPE_LESSON, $onlyA->id);

        $b = $this->package('B');
        $this->attach($b, PackageItem::TYPE_LESSON, $shared->id);
        $this->attach($b, PackageItem::TYPE_LESSON, $onlyB->id);

        $this->grant($a); // grants OnlyA; reuses the pre-owned Shared
        $this->grant($b); // grants OnlyB; reuses Shared again

        // Exactly three lesson rows total (Shared once), never one per package.
        $this->assertSame(3, $this->lessonEnrollments()->count());
        $this->assertSame(1, $this->lessonEnrollments()->where('lesson_id', $shared->id)->count());

        // The pre-existing direct grant is untouched — its provenance stays null.
        $sharedRow = $this->lessonEnrollments()->firstWhere('lesson_id', $shared->id);
        $this->assertNull($sharedRow->package_id);
    }

    public function test_fan_out_grants_access_but_defers_windows_to_sequential_unlock(): void
    {
        // A package buy grants ACCESS to every descendant lesson, but does NOT open
        // every window — the sequential-unlock engine (B14 / VD R5) opens only the
        // first lesson's window; the rest open one at a time as each is completed.
        $a = $this->package('A');
        $first = $this->lesson('First', availabilityDays: 7);
        $second = $this->lesson('Second', availabilityDays: 7);
        $this->attach($a, PackageItem::TYPE_LESSON, $first->id);   // sort_order 0
        $this->attach($a, PackageItem::TYPE_LESSON, $second->id);  // sort_order 1

        $this->grant($a);

        // Access granted to both (per-lesson enrollments exist)...
        $this->assertSame(2, $this->lessonEnrollments()->count());
        // ...but only the first lesson's window is open.
        $this->assertDatabaseHas('lesson_access_windows', ['user_id' => $this->userId, 'lesson_id' => $first->id]);
        $this->assertSame(0, LessonAccessWindow::withoutGlobalScopes()->where('lesson_id', $second->id)->count());
    }

    public function test_empty_package_grants_nothing(): void
    {
        $empty = $this->package('Empty');

        $this->assertSame(0, $this->grant($empty));
        $this->assertSame(0, $this->lessonEnrollments()->count());
    }
}
