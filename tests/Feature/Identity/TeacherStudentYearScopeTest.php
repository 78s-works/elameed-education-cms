<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * Who a member may see, as opposed to what they may do (M20). An assistant hired
 * for one academic year must not reach another year's students — not through the
 * roster, and not by addressing a student uuid directly.
 */
class TeacherStudentYearScopeTest extends TestCase
{
    use GrantsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $yearOne;

    private AcademicYear $yearThree;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->yearOne = $this->makeYear('Year One', 0);
        $this->yearThree = $this->makeYear('Year Three', 2);
    }

    private function makeYear(string $name, int $sort): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    /** @param  list<string>  $permissions */
    private function member(TenantUserRole $role, array $permissions = []): array
    {
        $user = User::factory()->create();
        $membership = TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        if ($permissions !== []) {
            $this->grantPermissions($user, $this->tenant, $permissions);
        }

        return [$user, $membership];
    }

    private function student(string $name, AcademicYear $year): User
    {
        $user = User::factory()->create(['name' => $name]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
        $profile = new StudentProfile(['user_id' => $user->id, 'academic_year_id' => $year->id]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    public function test_roster_is_scoped_to_the_active_year(): void
    {
        [$teacher] = $this->member(TenantUserRole::Teacher, ['students.view']);
        $this->student('Year one student', $this->yearOne);
        $this->student('Year three student', $this->yearThree);

        Sanctum::actingAs($teacher);
        $res = $this->withHeaders([
            'X-Tenant' => 'demo',
            'X-Academic-Year' => $this->yearOne->uuid,
        ])->getJson('/api/v1/teacher/students')->assertOk();

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertSame(['Year one student'], $names);
    }

    public function test_a_year_scoped_assistant_gets_only_their_years_even_without_the_header(): void
    {
        [$assistant, $membership] = $this->member(TenantUserRole::Assistant, ['students.view']);
        $membership->academicYears()->attach($this->yearOne->id);

        $this->student('Year one student', $this->yearOne);
        $this->student('Year three student', $this->yearThree);

        Sanctum::actingAs($assistant);
        // No X-Academic-Year: the roster must fall back to the assistant's years,
        // NOT to every year in the academy.
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/teacher/students')->assertOk();

        $this->assertSame(['Year one student'], collect($res->json('data'))->pluck('name')->all());
    }

    public function test_an_unscoped_member_without_a_header_still_sees_every_year(): void
    {
        [$teacher] = $this->member(TenantUserRole::Teacher, ['students.view']);
        $this->student('Year one student', $this->yearOne);
        $this->student('Year three student', $this->yearThree);

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/teacher/students')->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_a_year_scoped_assistant_cannot_open_another_years_student_by_uuid(): void
    {
        [$assistant, $membership] = $this->member(TenantUserRole::Assistant, [
            'students.view', 'students.activity.view', 'students.wallet.view',
        ]);
        $membership->academicYears()->attach($this->yearOne->id);

        $mine = $this->student('Year one student', $this->yearOne);
        $theirs = $this->student('Year three student', $this->yearThree);

        Sanctum::actingAs($assistant);
        $headers = ['X-Tenant' => 'demo', 'X-Academic-Year' => $this->yearOne->uuid];

        $this->withHeaders($headers)->getJson("/api/v1/teacher/students/{$mine->uuid}")->assertOk();

        // Out of scope = invisible (404), not "forbidden".
        foreach (['', '/enrollments', '/wallet', '/orders', '/progress', '/activity', '/attendance', '/exam-results', '/parents', '/content-overrides'] as $sub) {
            $this->withHeaders($headers)
                ->getJson("/api/v1/teacher/students/{$theirs->uuid}{$sub}")
                ->assertNotFound();
        }
    }

    public function test_an_unscoped_teacher_can_open_any_years_student(): void
    {
        [$teacher] = $this->member(TenantUserRole::Teacher, ['students.view']);
        $student = $this->student('Year three student', $this->yearThree);

        Sanctum::actingAs($teacher);
        // Panel sitting on year one; the student belongs to year three. The owner
        // is unscoped, so this must still open (the page speaks the student's year).
        $this->withHeaders([
            'X-Tenant' => 'demo',
            'X-Academic-Year' => $this->yearOne->uuid,
        ])->getJson("/api/v1/teacher/students/{$student->uuid}")->assertOk();
    }

    public function test_the_summary_withholds_slices_the_role_does_not_grant(): void
    {
        [$assistant] = $this->member(TenantUserRole::Assistant, ['students.view']);
        $student = $this->student('Year one student', $this->yearOne);

        Sanctum::actingAs($assistant);
        $summary = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk()
            ->json('data.summary');

        // Access rights and order counts ride on students.view.
        $this->assertArrayHasKey('enrolled_courses', $summary);
        $this->assertArrayHasKey('orders', $summary);
        $this->assertArrayHasKey('subscription', $summary);

        // Money needs students.wallet.view; learning figures need
        // students.activity.view. Neither was granted.
        $this->assertArrayNotHasKey('wallet_balance_minor', $summary);
        foreach ([
            'lessons_completed', 'content_completion_percent', 'attendance_percent',
            'average_exam_percent', 'last_active_at', 'devices_count',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $summary);
        }
    }

    public function test_the_summary_includes_every_slice_the_role_does_grant(): void
    {
        [$assistant] = $this->member(TenantUserRole::Assistant, [
            'students.view', 'students.wallet.view', 'students.activity.view',
        ]);
        $student = $this->student('Year one student', $this->yearOne);

        Sanctum::actingAs($assistant);
        $summary = $this->withHeader('X-Tenant', 'demo')
            ->getJson("/api/v1/teacher/students/{$student->uuid}")
            ->assertOk()
            ->json('data.summary');

        $this->assertArrayHasKey('wallet_balance_minor', $summary);
        $this->assertArrayHasKey('content_completion_percent', $summary);
        $this->assertArrayHasKey('attendance_percent', $summary);
        $this->assertArrayHasKey('devices_count', $summary);
    }

    public function test_the_year_picker_offers_only_the_years_the_member_is_assigned_to(): void
    {
        [$assistant, $membership] = $this->member(TenantUserRole::Assistant, ['content.view']);
        $membership->academicYears()->attach($this->yearOne->id);

        Sanctum::actingAs($assistant);
        $res = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/teacher/academic-years')->assertOk();

        // Offering an unassigned year would put the panel on a header the API
        // rejects 403 `academic_year_out_of_scope` — an empty page with no reason.
        $this->assertSame(['Year One'], collect($res->json('data'))->pluck('name')->all());

        [$teacher] = $this->member(TenantUserRole::Teacher, ['content.view']);
        Sanctum::actingAs($teacher);
        $all = $this->withHeader('X-Tenant', 'demo')
            ->getJson('/api/v1/teacher/academic-years')->assertOk();
        $this->assertCount(2, $all->json('data'));
    }

    public function test_an_assistant_without_student_permissions_reaches_nothing(): void
    {
        [$assistant] = $this->member(TenantUserRole::Assistant);
        $student = $this->student('Year one student', $this->yearOne);

        Sanctum::actingAs($assistant);
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertForbidden();
        foreach (['', '/enrollments', '/wallet', '/orders', '/progress', '/activity', '/attendance', '/exam-results', '/parents', '/content-overrides'] as $sub) {
            $this->withHeaders($h)
                ->getJson("/api/v1/teacher/students/{$student->uuid}{$sub}")
                ->assertForbidden();
        }
    }
}
