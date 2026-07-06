<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => TenantStatus::Active]);
    }

    private function makeTeacher(Tenant $tenant): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Create a course directly for a tenant (no request context in tests). */
    private function makeCourse(Tenant $tenant, array $attrs = []): Course
    {
        $course = new Course(array_merge([
            'title' => 'Course '.uniqid(),
            'visibility' => ContentVisibility::Visible->value,
        ], $attrs));
        $course->tenant_id = $tenant->id;
        $course->slug = $attrs['slug'] ?? ('course-'.uniqid());
        $course->save();

        return $course;
    }

    public function test_teacher_can_create_course_and_build_structure(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        // Create course
        $course = $this->withHeaders($h)->postJson('/api/v1/teacher/courses', [
            'title' => 'Algebra 101',
            'price_minor' => 15000,
            'visibility' => 'visible',
        ])->assertStatus(201)->assertJsonPath('data.title', 'Algebra 101')->json('data');

        $uuid = $course['uuid'];
        $this->assertNotEmpty($course['slug']);

        // Add a unit
        $unit = $this->withHeaders($h)->postJson("/api/v1/teacher/courses/{$uuid}/units", [
            'title' => 'Chapter 1',
        ])->assertStatus(201)->json('data');

        // Add a lesson under the unit — its course_id is inherited from the unit
        $this->withHeaders($h)->postJson("/api/v1/teacher/units/{$unit['id']}/lessons", [
            'title' => 'Lesson 1',
            'is_free_preview' => true,
        ])->assertStatus(201)
            ->assertJsonPath('data.is_free_preview', true)
            ->assertJsonPath('data.course_id', $unit['course_id']);
    }

    public function test_arabic_title_gets_usable_slug(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));

        $slug = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/courses', [
            'title' => 'الرياضيات', // no ASCII → fallback slug
        ])->assertStatus(201)->json('data.slug');

        $this->assertNotEmpty($slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function test_student_cannot_manage_courses(): void
    {
        $tenant = $this->makeTenant('demo');
        $student = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $student->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);
        Sanctum::actingAs($student);

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/courses', [
            'title' => 'Nope',
        ])->assertStatus(403);
    }

    /**
     * Cross-tenant isolation — the sole guard on MySQL. A teacher of tenant A
     * cannot reach tenant B's course even with its exact uuid (route binding is
     * tenant-scoped → 404).
     */
    public function test_cross_tenant_course_access_is_404(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');
        $teacherA = $this->makeTeacher($tenantA);
        $courseB = $this->makeCourse($tenantB, ['title' => 'B Secret']);

        Sanctum::actingAs($teacherA);

        // Teacher A, on tenant A's host, requesting tenant B's course uuid.
        $this->withHeaders(['X-Tenant' => 'alpha'])
            ->getJson("/api/v1/teacher/courses/{$courseB->uuid}")
            ->assertStatus(404);

        $this->withHeaders(['X-Tenant' => 'alpha'])
            ->putJson("/api/v1/teacher/courses/{$courseB->uuid}", ['title' => 'hacked'])
            ->assertStatus(404);

        $this->assertSame('B Secret', $courseB->fresh()->title);
    }

    public function test_public_catalogue_shows_only_published_courses_of_the_tenant(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');

        $this->makeCourse($tenantA, ['title' => 'A Visible', 'visibility' => ContentVisibility::Visible->value]);
        $this->makeCourse($tenantA, ['title' => 'A Hidden', 'visibility' => ContentVisibility::Hidden->value]);
        $this->makeCourse($tenantB, ['title' => 'B Visible', 'visibility' => ContentVisibility::Visible->value]);

        $response = $this->withHeaders(['X-Tenant' => 'alpha'])->getJson('/api/v1/courses');
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('A Visible', $titles);
        $this->assertNotContains('A Hidden', $titles);   // hidden excluded
        $this->assertNotContains('B Visible', $titles);   // other tenant excluded
    }

    public function test_public_course_detail_404_for_hidden_course(): void
    {
        $tenant = $this->makeTenant('demo');
        $hidden = $this->makeCourse($tenant, ['visibility' => ContentVisibility::Hidden->value, 'slug' => 'hidden-course']);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/courses/hidden-course')
            ->assertStatus(404);
    }

    public function test_attachment_link_can_be_added_to_a_lesson(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        // Set the tenant context so BelongsToTenant auto-fills tenant_id on create.
        app(TenantContext::class)->setTenant($tenant);
        $course = $this->makeCourse($tenant);
        $unit = $course->units()->create(['title' => 'U1']);
        $lesson = $unit->lessons()->create(['title' => 'L1', 'course_id' => $course->id]);

        $this->withHeaders($h)->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", [
            'type' => 'link',
            'title' => 'Reference',
            'url' => 'https://example.com/notes.pdf',
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'link')
            ->assertJsonPath('data.url', 'https://example.com/notes.pdf');

        $this->withHeaders($h)->getJson("/api/v1/teacher/lessons/{$lesson->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
