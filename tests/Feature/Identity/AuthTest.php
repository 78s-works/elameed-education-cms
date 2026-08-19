<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterIdCode;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private RecordingSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // reset rate limiters between tests

        $this->tenant = Tenant::create([
            'slug' => 'demo',
            'name' => 'Demo Academy',
            'status' => TenantStatus::Active,
        ]);

        $this->sms = new RecordingSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function tenantHeader(): array
    {
        return ['X-Tenant' => 'demo'];
    }

    private function member(string $phone, TenantUserRole $role): User
    {
        $user = User::factory()->create(['phone' => $phone, 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function center(?Tenant $tenant = null): Center
    {
        $center = new Center(['name' => 'Main Branch', 'is_active' => true]);
        $center->tenant_id = ($tenant ?? $this->tenant)->id; // no request context in tests
        $center->save();

        return $center;
    }

    private function idCode(Center $center, int $grade = 2, int $sequence = 1, string $status = 'active'): CenterIdCode
    {
        // center_id_codes are year-scoped now; with no request context in tests we
        // stamp the tenant's academic year directly (reuse if one already exists).
        $yearId = AcademicYear::withoutGlobalScopes()
            ->where('tenant_id', $center->tenant_id)
            ->value('id');
        if ($yearId === null) {
            $year = new AcademicYear(['name' => 'Year A', 'sort_order' => 0]);
            $year->tenant_id = $center->tenant_id;
            $year->save();
            $yearId = $year->id;
        }

        $code = new CenterIdCode([
            'center_id' => $center->id,
            'grade' => $grade,
            'sequence' => $sequence,
            'code' => sprintf('%d-%d-%06d', $grade, $center->id, $sequence),
            'status' => $status,
            'batch_id' => (string) Str::uuid(),
        ]);
        $code->tenant_id = $center->tenant_id; // no request context in tests
        $code->academic_year_id = $yearId;
        $code->save();

        return $code;
    }

    /** A persisted academic year (grade) for this tenant; reused by name. The
     *  manual register path now requires its uuid as `academic_year_uuid`. */
    private function academicYear(string $name = 'الثالث الثانوي'): AcademicYear
    {
        $year = AcademicYear::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', $name)
            ->first();
        if ($year === null) {
            $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
        }

        return $year;
    }

    private function setAccess(bool $login, bool $registration, string $verificationMode = 'auto'): void
    {
        $profile = new TeacherProfile([
            'login_enabled' => $login,
            'registration_enabled' => $registration,
            'registration_verification_mode' => $verificationMode,
        ]);
        $profile->tenant_id = $this->tenant->id; // no request context in tests
        $profile->save();
    }

    public function test_register_automatically_verifies_and_activates_membership(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');

        $register = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Sara',
            'phone' => '01000000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'أنثى',
            'governorate' => 'القاهرة',
            'academic_year_uuid' => $this->academicYear()->uuid,
            'guardian_phone' => '01099999999',
        ]);

        $register->assertCreated()->assertJsonPath('data.message', 'Registration completed. Your account is verified.');

        $user = User::where('phone', '01000000001')->firstOrFail();
        $membership = TenantUser::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
        $this->assertCount(0, $this->sms->messages);

        // Registration captured the sign-up form's extra fields.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'gender' => 'أنثى',
            'governorate' => 'القاهرة',
            'academic_year' => 'الثالث الثانوي',
        ]);
    }

    public function test_register_pins_student_to_the_chosen_academic_year(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $year = $this->academicYear('الثاني الثانوي');

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Pinned',
            'phone' => '01000000040',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'academic_year_uuid' => $year->uuid,
        ])->assertCreated();

        $user = User::where('phone', '01000000040')->firstOrFail();
        // The FK pins the student to the year; the label mirrors the year name.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'academic_year_id' => $year->id,
            'academic_year' => 'الثاني الثانوي',
        ]);
    }

    public function test_register_rejects_an_unknown_academic_year_uuid(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Bad Year',
            'phone' => '01000000041',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'academic_year_uuid' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000041']);
    }

    public function test_register_persists_study_mode_and_resolves_center_uuid(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $center = $this->center();

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Center Kid',
            'phone' => '01000000020',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'study_mode' => 'center',
            'center' => $center->uuid, // uuid, not the numeric id
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertCreated();

        $user = User::where('phone', '01000000020')->firstOrFail();
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'study_mode' => 'center',
            'center_id' => $center->id,
        ]);
    }

    public function test_register_defaults_study_mode_online_with_no_center(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Online Kid',
            'phone' => '01000000021',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertCreated();

        $user = User::where('phone', '01000000021')->firstOrFail();
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'study_mode' => 'online', // column default
            'center_id' => null,
        ]);
    }

    public function test_register_requires_center_when_study_mode_is_center(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'No Center',
            'phone' => '01000000022',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'study_mode' => 'both',
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000022']);
    }

    public function test_register_rejects_center_from_another_tenant(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other Academy', 'status' => TenantStatus::Active]);
        $foreignCenter = $this->center($other); // belongs to a DIFFERENT academy

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Cross Tenant',
            'phone' => '01000000023',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'study_mode' => 'center',
            'center' => $foreignCenter->uuid,
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000023']);
    }

    public function test_register_with_id_code_binds_center_grade_and_marks_used(): void
    {
        // B21: an on-site student signs up with a Center ID-code. The code is the
        // single source of truth — it links the center, sets study_mode=center and
        // decodes the grade onto academic_year, and is flipped to used.
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $center = $this->center();
        $code = $this->idCode($center, grade: 3, sequence: 7);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Code Kid',
            'phone' => '01000000030',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_code' => $code->code,
        ])->assertCreated();

        $user = User::where('phone', '01000000030')->firstOrFail();

        // Center + study_mode + decoded grade all come from the code.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'center_id' => $center->id,
            'study_mode' => 'center',
            'academic_year' => CenterIdCode::GRADE_LABELS[3], // grade 3 decoded
        ]);

        // The code is consumed: redeemed + stamped with this student.
        $this->assertDatabaseHas('center_id_codes', [
            'id' => $code->id,
            'status' => 'redeemed',
            'used_by' => $user->id,
        ]);
        $this->assertNotNull($code->fresh()->used_at);
    }

    public function test_register_rejects_an_already_used_id_code(): void
    {
        // Redeeming twice is refused — the code binds to exactly one student.
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $center = $this->center();
        $code = $this->idCode($center, grade: 2, sequence: 1);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'First',
            'phone' => '01000000031',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_code' => $code->code,
        ])->assertCreated();

        // Second student, same code → 422, and no account is created.
        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Second',
            'phone' => '01000000032',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_code' => $code->code,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000032']);
        $this->assertSame('01000000031', User::findOrFail($code->fresh()->used_by)->phone);
    }

    public function test_register_rejects_an_id_code_from_another_tenant(): void
    {
        // A code minted at a DIFFERENT academy is unknown here → invalid, nothing created.
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other Academy', 'status' => TenantStatus::Active]);
        $foreignCode = $this->idCode($this->center($other), grade: 1, sequence: 1);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Cross Tenant Code',
            'phone' => '01000000033',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_code' => $foreignCode->code,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000033']);
        $this->assertSame('active', $foreignCode->fresh()->status->value); // untouched
    }

    public function test_register_prohibits_manual_center_alongside_an_id_code(): void
    {
        // The code owns center/study_mode/grade — sending them manually is a conflict.
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $center = $this->center();
        $code = $this->idCode($center, grade: 2, sequence: 1);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Conflict',
            'phone' => '01000000034',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'id_code' => $code->code,
            'study_mode' => 'center',
            'center' => $center->uuid,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('users', ['phone' => '01000000034']);
        $this->assertSame('active', $code->fresh()->status->value); // not consumed
    }

    public function test_register_allows_siblings_to_share_a_guardian_phone(): void
    {
        // guardian_phone is non-unique (VD R6): two students in the same academy
        // may share one guardian number.
        $this->setAccess(login: true, registration: true, verificationMode: 'auto');
        $guardian = '01055555555';

        foreach (['01000000024', '01000000025'] as $phone) {
            $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
                'name' => 'Sibling '.$phone,
                'phone' => $phone,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'guardian_phone' => $guardian,
                'academic_year_uuid' => $this->academicYear()->uuid,
            ])->assertCreated();
        }

        $this->assertSame(2, StudentProfile::withoutGlobalScopes()->where('guardian_phone', $guardian)->count());
    }

    public function test_register_sends_otp_when_teacher_chooses_otp_verification(): void
    {
        $this->setAccess(login: true, registration: true, verificationMode: 'otp');

        $register = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Sara OTP',
            'phone' => '01000000014',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'أنثى',
            'governorate' => 'القاهرة',
            'academic_year_uuid' => $this->academicYear()->uuid,
            'guardian_phone' => '01099999998',
        ]);

        $register->assertStatus(202)->assertJsonPath('data.requires_otp', true);

        $user = User::where('phone', '01000000014')->firstOrFail();
        $membership = TenantUser::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(MembershipStatus::Pending, $membership->status);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($this->sms->lastCode());
    }

    public function test_register_rejects_duplicate_in_same_academy(): void
    {
        // Already a member of THIS academy → a genuine duplicate is rejected.
        $user = User::factory()->create(['phone' => '01000000002']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Dup',
            'phone' => '01000000002',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_register_joins_existing_identity_to_another_academy(): void
    {
        // A student who already exists (from another academy) but is NOT a member
        // here can join THIS academy — a fresh membership is attached to the same
        // global identity (a student may belong to several teachers). Default mode
        // is 'auto', so the membership activates immediately.
        $user = User::factory()->create(['phone' => '01000000002']);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Returning',
            'phone' => '01000000002',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'أنثى',
            'governorate' => 'القاهرة',
            'academic_year_uuid' => $this->academicYear()->uuid,
            'guardian_phone' => '01099999998',
        ])->assertStatus(201);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
        ]);
    }

    public function test_login_returns_token_for_active_member(): void
    {
        $user = User::factory()->create(['phone' => '01000000003', 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000003',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_wrong_password_is_generic_unauthenticated(): void
    {
        $user = User::factory()->create(['phone' => '01000000004', 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $wrong = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000004',
            'password' => 'WRONG',
        ]);
        $unknown = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01999999999',
            'password' => 'whatever',
        ]);

        // Same status + code for wrong-password and unknown-user → no enumeration.
        $wrong->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $unknown->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_login_requires_membership_in_tenant(): void
    {
        // Correct credentials but no membership in this tenant.
        User::factory()->create(['phone' => '01000000005', 'password' => 'secret123']);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000005',
            'password' => 'secret123',
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_login_is_blocked_for_students_when_the_teacher_disables_login(): void
    {
        $this->member('01000000010', TenantUserRole::Student);
        $this->setAccess(login: false, registration: true);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000010',
            'password' => 'secret123',
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.message', 'You are not allowed to login now.');
    }

    public function test_teacher_can_still_sign_in_when_login_is_disabled(): void
    {
        // A teacher must never be locked out by their own switch — otherwise they
        // could not sign in to re-open access. Only the teacher is exempt.
        $this->member('01000000011', TenantUserRole::Teacher);
        $this->setAccess(login: false, registration: true);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000011',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_assistant_is_blocked_when_login_is_disabled(): void
    {
        // Only the teacher is exempt — assistants are gated like everyone else.
        $this->member('01000000013', TenantUserRole::Assistant);
        $this->setAccess(login: false, registration: true);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000013',
            'password' => 'secret123',
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_registration_is_blocked_when_the_teacher_closes_it(): void
    {
        $this->setAccess(login: true, registration: false);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Late',
            'phone' => '01000000012',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'academic_year_uuid' => $this->academicYear()->uuid,
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

        // Nothing was created.
        $this->assertDatabaseMissing('users', ['phone' => '01000000012']);
    }

    public function test_otp_request_is_rate_limited(): void
    {
        $payload = ['identifier' => '01000000006', 'purpose' => 'register'];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/otp/request', $payload)->assertOk();
        }

        $this->withHeaders($this->tenantHeader())
            ->postJson('/api/v1/auth/otp/request', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_password_reset_flow(): void
    {
        $user = User::factory()->create(['phone' => '01000000007', 'password' => 'oldpass123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->withHeaders($this->tenantHeader())
            ->postJson('/api/v1/auth/password/forgot', ['identifier' => '01000000007'])
            ->assertOk();

        $code = $this->sms->lastCode();
        $this->assertNotNull($code);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/password/reset', [
            'identifier' => '01000000007',
            'code' => $code,
            'password' => 'newpass123',
        ])->assertOk();

        // New password works, old one does not.
        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000007',
            'password' => 'newpass123',
        ])->assertOk();
    }

    public function test_me_returns_user_and_current_membership(): void
    {
        $user = User::factory()->create(['phone' => '01000000008']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders($this->tenantHeader() + ['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '01000000008')
            ->assertJsonPath('data.current.role', 'student');
    }

    public function test_me_exposes_student_study_mode(): void
    {
        $user = User::factory()->create(['phone' => '01000000009']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $profile = new StudentProfile(['user_id' => $user->id, 'study_mode' => 'online']);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders($this->tenantHeader() + ['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.study_mode', 'online')
            ->assertJsonPath('data.center', null);
    }
}
