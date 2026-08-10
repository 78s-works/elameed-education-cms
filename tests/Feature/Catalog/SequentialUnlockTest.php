<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Events\LessonCompleted;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\LessonProgress;
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
 * Sequential unlock engine (B14 / VD R5, doc 12 §4.2): buying a package opens only
 * the FIRST lesson's window; each next lesson's window opens automatically when the
 * previous lesson is COMPLETED (watched to completion — VD-D3, never on expiry),
 * with its own independent 7-day timer; the sequence follows `package_items.sort_order`
 * recursively; and a student can't jump ahead of the sequence.
 */
class SequentialUnlockTest extends TestCase
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

    private function student(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function lesson(string $name = 'L', int $availabilityDays = 7): Lesson
    {
        $lesson = new Lesson(['title' => $name, 'availability_days' => $availabilityDays]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function package(string $accessMode = 'both'): Package
    {
        $package = new Package(['name' => 'P', 'access_mode' => $accessMode]);
        $package->tenant_id = $this->tenant->id;
        $package->academic_year_id = $this->year->id;
        $package->save();

        return $package->fresh();
    }

    private function attach(Package $package, string $type, int $itemId, int $sort): void
    {
        $item = new PackageItem(['package_id' => $package->id, 'item_type' => $type, 'item_id' => $itemId, 'sort_order' => $sort]);
        $item->tenant_id = $this->tenant->id;
        $item->save();
    }

    private function buy(User $student, Package $package): void
    {
        app(EnrollmentService::class)->grantPackage($this->tenant->id, $student->id, $package, EnrollmentSource::Purchase);
    }

    /** Mark a lesson watched-to-completion for a student (the VD-D3 unlock trigger). */
    private function complete(User $student, Lesson $lesson): void
    {
        $progress = new LessonProgress([
            'lesson_id' => $lesson->id, 'user_id' => $student->id,
            'watch_percent' => 100, 'completed_at' => now(),
        ]);
        $progress->tenant_id = $this->tenant->id;
        $progress->save();
    }

    private function window(User $student, Lesson $lesson): ?LessonAccessWindow
    {
        return LessonAccessWindow::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();
    }

    private function sections(User $student, Lesson $lesson)
    {
        Sanctum::actingAs($student);

        return $this->withHeader('X-Tenant', 'demo')->getJson("/api/v1/lessons/{$lesson->id}/sections");
    }

    // --- criterion 1: only lesson 1 opens on purchase ----------------------

    public function test_buying_a_package_opens_only_the_first_lesson_window(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2, $l3] = [$this->lesson('L1'), $this->lesson('L2'), $this->lesson('L3')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->attach($pkg, 'lesson', $l3->id, 2);

        $this->buy($student, $pkg);

        // Access fanned out to all three; only the first window is open.
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'lesson_id' => $l3->id, 'package_id' => $pkg->id]);
        $this->assertNotNull($this->window($student, $l1));
        $this->assertNull($this->window($student, $l2));
        $this->assertNull($this->window($student, $l3));
    }

    // --- criterion 2: next opens on completion only, and only the next -----

    public function test_completing_a_lesson_opens_only_the_next_one(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2, $l3] = [$this->lesson('L1'), $this->lesson('L2'), $this->lesson('L3')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->attach($pkg, 'lesson', $l3->id, 2);
        $this->buy($student, $pkg);

        // Complete L1 → the event worker opens L2's window, and NOT L3's (no skip).
        $this->complete($student, $l1);
        LessonCompleted::dispatch($this->tenant->id, $student->id, $l1->id);

        $this->assertNotNull($this->window($student, $l2));
        $this->assertNull($this->window($student, $l3), 'completing L1 must not skip to L3');

        // Now complete L2 → L3 opens.
        $this->complete($student, $l2);
        LessonCompleted::dispatch($this->tenant->id, $student->id, $l2->id);
        $this->assertNotNull($this->window($student, $l3));
    }

    public function test_expiry_alone_does_not_advance_the_sequence(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2] = [$this->lesson('L1'), $this->lesson('L2')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->buy($student, $pkg);

        // L1's window expires with L1 never completed → firing the event is a no-op
        // (VD-D3: expiry never unlocks the next lesson).
        LessonCompleted::dispatch($this->tenant->id, $student->id, $l1->id);
        $this->assertNull($this->window($student, $l2));
    }

    // --- criterion 3: each opened lesson gets its own independent 7-day timer

    public function test_each_opened_lesson_gets_its_own_7_day_window(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2] = [$this->lesson('L1'), $this->lesson('L2')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->buy($student, $pkg);

        // Three days later, complete L1 → L2's window opens fresh (not on L1's clock).
        $this->travel(3)->days();
        $this->complete($student, $l1);
        LessonCompleted::dispatch($this->tenant->id, $student->id, $l1->id);

        $w1 = $this->window($student, $l1);
        $w2 = $this->window($student, $l2);

        $this->assertEqualsWithDelta(7 * 86400, $w1->expires_at->getTimestamp() - $w1->started_at->getTimestamp(), 5);
        $this->assertEqualsWithDelta(7 * 86400, $w2->expires_at->getTimestamp() - $w2->started_at->getTimestamp(), 5);
        // L2's window opened later, so both its start AND end trail L1's by ~3 days.
        $this->assertTrue($w2->started_at->greaterThan($w1->started_at));
        $this->assertTrue($w2->expires_at->greaterThan($w1->expires_at));
    }

    // --- criterion 4: walks package_items.sort_order recursively -----------

    public function test_ordered_walk_is_depth_first_by_sort_order(): void
    {
        $parent = $this->package();
        $child = $this->package();
        [$a, $b, $c, $d] = [$this->lesson('A'), $this->lesson('B'), $this->lesson('C'), $this->lesson('D')];

        // parent: [ A(0), child(1), D(2) ] ; child: [ B(0), C(1) ]  =>  A, B, C, D
        $this->attach($parent, 'lesson', $a->id, 0);
        $this->attach($parent, 'package', $child->id, 1);
        $this->attach($parent, 'lesson', $d->id, 2);
        $this->attach($child, 'lesson', $b->id, 0);
        $this->attach($child, 'lesson', $c->id, 1);

        $ordered = app(PackageItemService::class)->orderedLessonIds($parent)->all();

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], $ordered);
    }

    public function test_nested_package_purchase_opens_only_the_first_lesson_deep(): void
    {
        $student = $this->student();
        $parent = $this->package();
        $child = $this->package();
        [$b, $c, $d] = [$this->lesson('B'), $this->lesson('C'), $this->lesson('D')];
        // parent starts with a sub-package whose first lesson is the sequence head.
        $this->attach($parent, 'package', $child->id, 0);
        $this->attach($parent, 'lesson', $d->id, 1);
        $this->attach($child, 'lesson', $b->id, 0);
        $this->attach($child, 'lesson', $c->id, 1);

        $this->buy($student, $parent);

        $this->assertNotNull($this->window($student, $b), 'B is the deep first lesson and must open');
        $this->assertNull($this->window($student, $c));
        $this->assertNull($this->window($student, $d));
    }

    // --- no skip: the content gate blocks later lessons --------------------

    public function test_sequence_gate_blocks_later_lessons_until_previous_completed(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2] = [$this->lesson('L1'), $this->lesson('L2')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->buy($student, $pkg);

        // L1 (first) is open; L2 is locked until L1 is completed.
        $this->sections($student, $l1)->assertOk();
        $this->sections($student, $l2)->assertStatus(423)->assertSee('prev_lesson_incomplete');

        $this->complete($student, $l1);
        $this->sections($student, $l2)->assertOk();
    }

    // --- end-to-end: the real completion endpoint drives the engine --------

    public function test_progress_completion_endpoint_advances_the_sequence(): void
    {
        $student = $this->student();
        $pkg = $this->package();
        [$l1, $l2] = [$this->lesson('L1'), $this->lesson('L2')];
        $this->attach($pkg, 'lesson', $l1->id, 0);
        $this->attach($pkg, 'lesson', $l2->id, 1);
        $this->buy($student, $pkg);

        $this->assertNull($this->window($student, $l2));

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/lessons/{$l1->id}/progress", ['watch_percent' => 100])
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        // Watching L1 to completion opened L2's window through the event listener.
        $this->assertNotNull($this->window($student, $l2));
    }

    // --- regression: a standalone lesson buy is never sequence-gated -------

    public function test_standalone_lesson_buy_is_not_sequence_gated(): void
    {
        $student = $this->student();
        $lesson = $this->lesson('Solo');

        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        // grantLesson opens the window immediately (unchanged), and no package
        // sequence gate applies.
        $this->assertNotNull($this->window($student, $lesson));
        $this->sections($student, $lesson)->assertOk();
    }
}
