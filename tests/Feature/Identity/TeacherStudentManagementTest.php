<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
        Sanctum::actingAs($this->member($this->tenant, TenantUserRole::Teacher));
    }

    private function member(Tenant $tenant, TenantUserRole $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function course(): Course
    {
        $c = new Course(['title' => 'Course', 'visibility' => ContentVisibility::Visible->value, 'purchase_enabled' => true]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'course-'.uniqid();
        $c->save();

        return $c;
    }

    public function test_teacher_can_add_student_and_gets_generated_password(): void
    {
        $res = $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Offline Kid', 'phone' => '01555000001',
        ])->assertStatus(201);

        $res->assertJsonPath('data.status', 'active');
        $this->assertNotEmpty($res->json('data.temporary_password'));

        $user = User::where('phone', '01555000001')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'student', 'status' => 'active',
        ]);
    }

    public function test_show_returns_360_summary(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student, ['phone' => '01555000002']);

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk()
            ->assertJsonPath('data.phone', '01555000002')
            ->assertJsonStructure(['data' => ['summary' => ['enrolled_courses', 'wallet_balance_minor', 'orders', 'lessons_completed']]]);
    }

    public function test_suspend_and_remove_student(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);

        // Suspend
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$student->uuid}", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        // Remove → membership gone
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/students/{$student->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'role' => 'student',
        ]);
    }

    public function test_manual_enroll_then_revoke(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $course = $this->course();

        $enrollmentId = $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$student->uuid}/enrollments", ['course' => $course->uuid])
            ->assertStatus(201)->json('data.id');

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$student->uuid}/enrollments")
            ->assertOk()->assertJsonPath('data.0.status', 'active');

        $this->withHeaders($this->h)
            ->deleteJson("/api/v1/teacher/students/{$student->uuid}/enrollments/{$enrollmentId}")
            ->assertNoContent();

        $this->assertSame(EnrollmentStatus::Cancelled, Enrollment::withoutGlobalScopes()->find($enrollmentId)->status);
    }

    public function test_wallet_credit_debit_and_low_balance_guard(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $base = "/api/v1/teacher/students/{$student->uuid}/wallet";

        $this->withHeaders($this->h)->postJson("{$base}/adjust", ['amount_minor' => 5000, 'direction' => 'credit'])
            ->assertOk()->assertJsonPath('data.balance_minor', 5000);

        $this->withHeaders($this->h)->postJson("{$base}/adjust", ['amount_minor' => 2000, 'direction' => 'debit'])
            ->assertOk()->assertJsonPath('data.balance_minor', 3000);

        // Cannot deduct more than the balance.
        $this->withHeaders($this->h)->postJson("{$base}/adjust", ['amount_minor' => 999999, 'direction' => 'debit'])
            ->assertStatus(422);

        // Ledger stays balanced.
        $debits = LedgerEntry::withoutGlobalScopes()->where('direction', 'debit')->sum('amount_minor');
        $credits = LedgerEntry::withoutGlobalScopes()->where('direction', 'credit')->sum('amount_minor');
        $this->assertSame((int) $debits, (int) $credits);
    }

    public function test_notify_creates_in_app_notification(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/notify", [
            'title' => 'Reminder', 'message' => 'Please finish lesson 3.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'type' => 'teacher.message',
        ]);
    }

    public function test_cross_tenant_student_is_not_reachable(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $otherStudent = $this->member($other, TenantUserRole::Student);

        // The acting teacher belongs to `demo`; requesting `other`'s student → 404.
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$otherStudent->uuid}")
            ->assertStatus(404);

        $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$otherStudent->uuid}/wallet/adjust", ['amount_minor' => 100, 'direction' => 'credit'])
            ->assertStatus(404);
    }
}
