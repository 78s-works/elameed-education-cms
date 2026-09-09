<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterIdCode;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Jobs\SendBroadcastJob;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        // Adding a student now requires a resolved academic year (student pinning).
        $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();
        $this->h = ['X-Tenant' => 'demo', 'X-Academic-Year' => $year->uuid];
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

    private function lesson(): Lesson
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();

        $l = new Lesson(['title' => 'Lesson', 'visibility' => ContentVisibility::Visible->value, 'is_purchasable' => true, 'price_minor' => 1000]);
        $l->tenant_id = $this->tenant->id;
        $l->academic_year_id = $year->id;
        $l->save();

        return $l;
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

    public function test_teacher_creates_center_student_with_an_unused_id_code(): void
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();

        $center = new Center(['name' => 'Main Center']);
        $center->tenant_id = $this->tenant->id;
        $center->save();

        $code = new CenterIdCode([
            'center_id' => $center->id,
            'grade' => 3,
            'sequence' => 1,
            'code' => '3-'.$center->id.'-000001',
            'status' => 'active',
            'batch_id' => (string) Str::uuid(),
        ]);
        $code->tenant_id = $this->tenant->id;
        $code->academic_year_id = $year->id;
        $code->save();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Center Kid', 'phone' => '01555000777',
            'study_mode' => 'center', 'id_code' => $code->code,
        ])->assertStatus(201)->assertJsonPath('data.study_mode', 'center');

        $user = User::where('phone', '01555000777')->firstOrFail();

        // Profile bound to the code's center + year; study_mode forced to center.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id, 'tenant_id' => $this->tenant->id,
            'study_mode' => 'center', 'center_id' => $center->id, 'academic_year_id' => $year->id,
        ]);

        // Code consumed — redeemed and bound to this student.
        $this->assertDatabaseHas('center_id_codes', [
            'id' => $code->id, 'status' => 'redeemed', 'used_by' => $user->id,
        ]);
    }

    public function test_center_student_requires_an_id_code(): void
    {
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'No Code', 'phone' => '01555000778', 'study_mode' => 'center',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['id_code']]]);
    }

    public function test_used_id_code_is_rejected(): void
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
        $center = new Center(['name' => 'Main Center']);
        $center->tenant_id = $this->tenant->id;
        $center->save();

        $code = new CenterIdCode([
            'center_id' => $center->id, 'grade' => 2, 'sequence' => 1,
            'code' => '2-'.$center->id.'-000001', 'status' => 'redeemed',
            'batch_id' => (string) Str::uuid(), 'used_at' => now(),
        ]);
        $code->tenant_id = $this->tenant->id;
        $code->academic_year_id = $year->id;
        $code->save();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/students', [
            'name' => 'Late Kid', 'phone' => '01555000779',
            'study_mode' => 'center', 'id_code' => $code->code,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['id_code']]]);

        // No student membership created — the whole create rolled back.
        $this->assertDatabaseMissing('users', ['phone' => '01555000779']);
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
        $lesson = $this->lesson();

        $enrollmentId = $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$student->uuid}/enrollments", ['target_type' => 'lesson', 'target' => (string) $lesson->id])
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

    /**
     * Messaging one student is the narrowest CUSTOM notification, so it records
     * a `notification_broadcasts` row aimed at that student and delivers through
     * the engine — not a row on the retired `notifications` table.
     */
    public function test_notify_records_a_custom_notification_for_the_student(): void
    {
        Queue::fake();

        $student = $this->member($this->tenant, TenantUserRole::Student);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/notify", [
            'title' => 'Reminder', 'message' => 'Please finish lesson 3.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notification_broadcasts', [
            'tenant_id' => $this->tenant->id,
            'audience_type' => 'students',
            'title_ar' => 'Reminder',
            'body_ar' => 'Please finish lesson 3.',
        ]);

        // Delivery is queued, never done inside the request.
        Queue::assertPushed(SendBroadcastJob::class);
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

    public function test_grant_is_refused_when_the_content_is_from_another_year(): void
    {
        // A student is pinned to ONE academic year and every student-facing query
        // is scoped to it, so content from another year would be granted and then
        // never appear for them. The target resolves against the TEACHER's active
        // year, which is not necessarily the student's — refuse the mismatch.
        $studentYear = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
        $student = $this->pinnedStudent($studentYear);

        $otherYear = new AcademicYear(['name' => 'Other Year', 'sort_order' => 1]);
        $otherYear->tenant_id = $this->tenant->id;
        $otherYear->save();
        $otherLesson = new Lesson(['title' => 'Other-year lesson', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 1000]);
        $otherLesson->tenant_id = $this->tenant->id;
        $otherLesson->academic_year_id = $otherYear->id;
        $otherLesson->save();

        // The teacher is browsing the OTHER year, so the lesson resolves for them.
        $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $otherYear->uuid])
            ->postJson("/api/v1/teacher/students/{$student->uuid}/enrollments", [
                'target_type' => 'lesson', 'target' => (string) $otherLesson->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'lesson_id' => $otherLesson->id]);
    }

    public function test_grant_succeeds_for_content_in_the_students_own_year(): void
    {
        $studentYear = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
        $student = $this->pinnedStudent($studentYear);
        $lesson = $this->lesson(); // same year as $this->h

        $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$student->uuid}/enrollments", [
                'target_type' => 'lesson', 'target' => (string) $lesson->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'lesson_id' => $lesson->id]);
    }

    public function test_student_detail_exposes_the_pinned_year_uuid(): void
    {
        // The panel scopes the grant picker with this, so it must be the uuid and
        // not only the free-text label.
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
        $student = $this->pinnedStudent($year);

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk()
            ->assertJsonPath('data.academic_year_uuid', $year->uuid)
            ->assertJsonPath('data.academic_year', $year->name);
    }

    /** A student membership WITH a profile pinned to $year. */
    private function pinnedStudent(AcademicYear $year): User
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $profile = new StudentProfile([
            'user_id' => $student->id,
            'academic_year_id' => $year->id,
            'academic_year' => $year->name,
            'study_mode' => 'center',
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $student;
    }
}
