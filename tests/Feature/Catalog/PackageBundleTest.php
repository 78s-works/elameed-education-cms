<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Teacher packages (bundles): a teacher groups courses + units into a package;
 * buying it opens every item — a whole-course item unlocks its lessons AND exams,
 * a unit item unlocks just that chapter's lessons. Non-bundled content stays shut.
 */
class PackageBundleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h = ['X-Tenant' => 'demo'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function course(string $title): Course
    {
        $course = new Course([
            'title' => $title, 'price_minor' => 10000,
            'visibility' => ContentVisibility::Visible->value, 'purchase_enabled' => true,
        ]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();

        return $course;
    }

    private function unit(Course $course, string $title): Unit
    {
        $unit = new Unit(['course_id' => $course->id, 'title' => $title]);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        return $unit;
    }

    private function lesson(Unit $unit, string $title): Lesson
    {
        $lesson = new Lesson(['course_id' => $unit->course_id, 'unit_id' => $unit->id, 'title' => $title]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    private function creditWallet(User $user, int $amount): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $user->id);
        $ledger->post($this->tenant->id, "test-topup:{$user->id}", [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount],
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
        ]);
    }

    public function test_teacher_creates_lists_and_publishes_a_package(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $courseA = $this->course('Course A');
        $unitB1 = $this->unit($this->course('Course B'), 'Chapter B1');
        Sanctum::actingAs($teacher);

        $create = $this->withHeaders($this->h)->postJson('/api/v1/teacher/bundles', [
            'title' => 'Starter Package',
            'price_minor' => 20000,
            'purchase_enabled' => true,
            'visibility' => 'visible',
            'items' => [
                ['type' => 'course', 'course' => $courseA->uuid],
                ['type' => 'unit', 'unit' => $unitB1->id],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.title', 'Starter Package')
            ->assertJsonCount(2, 'data.items');

        $slug = $create->json('data.slug');

        // Teacher list
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/bundles')
            ->assertOk()->assertJsonPath('data.0.items_count', 2);

        // Public storefront (published + purchasable)
        $this->withHeaders($this->h)->getJson("/api/v1/bundles/{$slug}")
            ->assertOk()->assertJsonPath('data.title', 'Starter Package')
            ->assertJsonCount(2, 'data.items');
    }

    public function test_buying_a_package_opens_every_item_but_not_unbundled_content(): void
    {
        // Course A (unit A1 → L1) with a published exam; Course B (unit B1 → L2,
        // unit B2 → L3 + L4). The package bundles: whole Course A, chapter B1, and
        // the single lesson L3 (part of chapter B2).
        $courseA = $this->course('Course A');
        $unitA1 = $this->unit($courseA, 'Chapter A1');
        $l1 = $this->lesson($unitA1, 'A1 Lesson');
        $examA = new Exam(['course_id' => $courseA->id, 'title' => 'Course A Quiz', 'is_published' => true]);
        $examA->tenant_id = $this->tenant->id;
        $examA->save();

        $courseB = $this->course('Course B');
        $unitB1 = $this->unit($courseB, 'Chapter B1');
        $l2 = $this->lesson($unitB1, 'B1 Lesson');
        $unitB2 = $this->unit($courseB, 'Chapter B2');
        $l3 = $this->lesson($unitB2, 'B2 Lesson 3');
        $l4 = $this->lesson($unitB2, 'B2 Lesson 4'); // sibling of L3, NOT bundled

        // Teacher assembles a package: whole Course A + chapter B1 + lesson L3.
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);
        $bundleUuid = $this->withHeaders($this->h)->postJson('/api/v1/teacher/bundles', [
            'title' => 'Mixed Package', 'price_minor' => 20000, 'purchase_enabled' => true, 'visibility' => 'visible',
            'items' => [
                ['type' => 'course', 'course' => $courseA->uuid],
                ['type' => 'unit', 'unit' => $unitB1->id],
                ['type' => 'lesson', 'lesson' => $l3->id],
            ],
        ])->assertStatus(201)->json('data.uuid');

        // Student buys the package from wallet.
        $student = $this->member(TenantUserRole::Student);
        $this->creditWallet($student, 30000);
        Sanctum::actingAs($student);

        $this->withHeaders($this->h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'bundle', 'bundle' => $bundleUuid]],
        ])->assertOk()->assertJsonPath('data.total_minor', 20000);

        $orderUuid = $this->withHeaders($this->h)->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'bundle', 'bundle' => $bundleUuid]],
        ])->assertStatus(201)->json('data.uuid');

        $this->withHeaders($this->h)->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'wallet',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        // Three enrollments were granted (course + unit + lesson), all bundle-linked.
        $courseGrant = Enrollment::withoutGlobalScopes()->where('user_id', $student->id)
            ->where('course_id', $courseA->id)->first();
        $unitGrant = Enrollment::withoutGlobalScopes()->where('user_id', $student->id)
            ->where('unit_id', $unitB1->id)->first();
        $lessonGrant = Enrollment::withoutGlobalScopes()->where('user_id', $student->id)
            ->where('lesson_id', $l3->id)->first();
        $this->assertNotNull($courseGrant, 'whole-course grant missing');
        $this->assertNotNull($unitGrant, 'unit grant missing');
        $this->assertNotNull($lessonGrant, 'lesson grant missing');
        $this->assertNotNull($courseGrant->bundle_id);
        $this->assertNotNull($unitGrant->bundle_id);
        $this->assertNotNull($lessonGrant->bundle_id);

        // Lesson access — progress store re-checks access (no video/transcode needed).
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$l1->id}/progress", ['watch_percent' => 10])
            ->assertOk(); // Course A (whole) → open
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$l2->id}/progress", ['watch_percent' => 10])
            ->assertOk(); // Chapter B1 → open via unit grant
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$l3->id}/progress", ['watch_percent' => 10])
            ->assertOk(); // Lesson L3 → open via lesson grant
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$l4->id}/progress", ['watch_percent' => 10])
            ->assertForbidden(); // L4 (sibling of L3, not bundled) → closed — proves lesson-level, not unit-level

        // A whole-course item also unlocks that course's exams.
        $this->withHeaders($this->h)->getJson('/api/v1/exams')
            ->assertOk()->assertJsonFragment(['uuid' => $examA->uuid]);

        // /me/courses surfaces both courses (whole-course + the one reached via a unit).
        $mine = $this->withHeaders($this->h)->getJson('/api/v1/me/courses')->assertOk()->json('data');
        $titles = array_column($mine, 'title');
        $this->assertContains('Course A', $titles);
        $this->assertContains('Course B', $titles);
    }
}
