<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherStudentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(Tenant $tenant, TenantUserRole $role, array $userAttrs = []): User
    {
        $user = User::factory()->create($userAttrs);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_teacher_sees_only_their_academy_students(): void
    {
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $this->member($this->tenant, TenantUserRole::Student, ['name' => 'Sara Ahmed']);
        $this->member($this->tenant, TenantUserRole::Student, ['name' => 'Omar Ali']);

        // A student in a DIFFERENT academy — must not appear.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $this->member($other, TenantUserRole::Student, ['name' => 'Outsider']);

        Sanctum::actingAs($teacher);
        $res = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/students')->assertOk();

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Sara Ahmed', $names);
        $this->assertContains('Omar Ali', $names);
        $this->assertNotContains('Outsider', $names);
        $this->assertSame(2, $res->json('meta.total'));
    }

    public function test_search_and_enrolled_count(): void
    {
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $sara = $this->member($this->tenant, TenantUserRole::Student, ['name' => 'Sara Ahmed', 'phone' => '01234567890']);
        $this->member($this->tenant, TenantUserRole::Student, ['name' => 'Omar Ali']);

        // Enrol Sara in one course → enrolled_courses should be 1.
        $course = new Course(['title' => 'Math', 'visibility' => ContentVisibility::Visible->value]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'math';
        $course->save();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $sara->id, $course, EnrollmentSource::Manual);

        Sanctum::actingAs($teacher);

        // Search narrows to Sara only.
        $res = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/students?q=Sara')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('Sara Ahmed', $res->json('data.0.name'));
        $this->assertSame(1, $res->json('data.0.enrolled_courses'));
    }

    public function test_student_cannot_list_students(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        Sanctum::actingAs($student);

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/students')->assertStatus(403);
    }
}
