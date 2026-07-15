<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\LoginAttempt;
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

    public function test_wallet_set_exact_balance_and_full_ledger(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $base = "/api/v1/teacher/students/{$student->uuid}/wallet";

        $this->withHeaders($this->h)->postJson("{$base}/adjust", ['amount_minor' => 10000, 'direction' => 'credit'])->assertOk();

        // Set to an exact LOWER amount → posts a debit delta.
        $this->withHeaders($this->h)->postJson("{$base}/set", ['balance_minor' => 3000])
            ->assertOk()->assertJsonPath('data.balance_minor', 3000);
        // Set HIGHER → credit delta.
        $this->withHeaders($this->h)->postJson("{$base}/set", ['balance_minor' => 8000])
            ->assertOk()->assertJsonPath('data.balance_minor', 8000);
        // Clear to zero.
        $this->withHeaders($this->h)->postJson("{$base}/set", ['balance_minor' => 0])
            ->assertOk()->assertJsonPath('data.balance_minor', 0);

        // Full ledger is paginated…
        $this->withHeaders($this->h)->getJson("{$base}/ledger")
            ->assertOk()->assertJsonStructure(['data', 'meta', 'links']);

        // …and the double-entry ledger stays balanced throughout.
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

    public function test_teacher_adds_and_edits_full_registration_fields(): void
    {
        $res = $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'طالب رباعي الاسم', 'phone' => '01555000200',
            'gender' => 'ذكر', 'governorate' => 'الجيزة', 'region' => 'فيصل',
            'academic_year' => 'الثالث الثانوي', 'education_type' => 'عام', 'guardian_phone' => '01099999900',
        ])->assertStatus(201);
        $res->assertJsonPath('data.governorate', 'الجيزة')->assertJsonPath('data.gender', 'ذكر');

        $uuid = User::where('phone', '01555000200')->firstOrFail()->uuid;
        $this->assertDatabaseHas('student_profiles', ['governorate' => 'الجيزة', 'academic_year' => 'الثالث الثانوي']);

        // Show returns the full profile.
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.education_type', 'عام')
            ->assertJsonPath('data.guardian_phone', '01099999900');

        // Teacher edits a field (full control).
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$uuid}", ['governorate' => 'القاهرة'])
            ->assertOk()->assertJsonPath('data.governorate', 'القاهرة');
        $this->assertDatabaseHas('student_profiles', ['governorate' => 'القاهرة']);

        // List row includes the fields too.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/students?q=01555000200')
            ->assertOk()->assertJsonPath('data.0.academic_year', 'الثالث الثانوي');
    }

    public function test_teacher_can_edit_student_identity(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student, ['phone' => '01555000010']);

        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$student->uuid}", [
            'name' => 'New Name', 'phone' => '01555000099', 'email' => 'new@ex.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '01555000099');

        $this->assertDatabaseHas('users', ['id' => $student->id, 'name' => 'New Name', 'phone' => '01555000099']);
    }

    public function test_cannot_add_a_student_with_a_phone_or_email_already_in_the_system(): void
    {
        // Someone already exists in the system (even in a DIFFERENT academy).
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $this->member($other, TenantUserRole::Student, ['phone' => '01555000300', 'email' => 'taken@ex.com']);

        // Duplicate phone → rejected.
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Dup Phone', 'phone' => '01555000300',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['phone']]]);

        // Duplicate email → rejected.
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Dup Email', 'phone' => '01555000301', 'email' => 'taken@ex.com',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['email']]]);

        // Same email in a different case / with whitespace is still caught (normalised).
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Dup Case', 'phone' => '01555000302', 'email' => '  TAKEN@EX.COM ',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_cannot_change_a_student_to_another_users_phone_but_can_keep_its_own(): void
    {
        $this->member($this->tenant, TenantUserRole::Student, ['phone' => '01555000400']);
        $b = $this->member($this->tenant, TenantUserRole::Student, ['phone' => '01555000401']);

        // Taking A's phone → rejected.
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$b->uuid}", ['phone' => '01555000400'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['phone']]]);

        // Re-saving B's OWN phone is allowed (unique rule ignores self).
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$b->uuid}", ['phone' => '01555000401'])
            ->assertOk();
    }

    public function test_teacher_can_force_password_reset_and_revoke_sessions(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $student->createToken('api'); // an existing session
        $originalHash = $student->password;

        $res = $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/reset-password")
            ->assertOk();

        $this->assertNotEmpty($res->json('data.temporary_password'));
        $this->assertNotSame($originalHash, $student->fresh()->password);   // password changed
        $this->assertSame(0, $student->fresh()->tokens()->count());          // sessions revoked
    }

    public function test_suspended_student_is_blocked_from_tenant_endpoints(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);

        // Suspend via the teacher.
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/students/{$student->uuid}", ['status' => 'suspended'])
            ->assertOk();

        // The student can no longer use authenticated tenant endpoints (this same
        // `active` middleware also guards obtaining a playback token).
        Sanctum::actingAs($student);
        $this->withHeaders($this->h)->getJson('/api/v1/me')->assertStatus(403);
        $this->withHeaders($this->h)->getJson('/api/v1/wallet')->assertStatus(403);
    }

    public function test_activity_timeline_and_export(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student, ['phone' => '01555000020']);

        // Seed a login attempt so the timeline has an event.
        LoginAttempt::create([
            'user_id' => $student->id, 'tenant_id' => $this->tenant->id,
            'identifier' => '01555000020', 'ip' => '127.0.0.1', 'success' => true,
        ]);

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$student->uuid}/activity")
            ->assertOk()->assertJsonPath('data.0.type', 'login');

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$student->uuid}/export")
            ->assertOk()
            ->assertJsonPath('data.profile.phone', '01555000020')
            ->assertJsonStructure(['data' => ['profile', 'membership', 'enrollments', 'orders', 'progress', 'wallet_balance_minor']]);
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
