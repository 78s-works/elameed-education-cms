<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Review;
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
 * Public landing resolution (LANDING_CONTRACT_V2.md) + minimal reviews.
 */
class LandingV2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function publishedCourse(): Course
    {
        $c = new Course(['title' => 'Algebra', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'algebra-'.uniqid();
        $c->save();

        $unit = new Unit(['course_id' => $c->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        foreach ([600, 900] as $d) { // 25 minutes total
            $l = new Lesson(['unit_id' => $unit->id, 'course_id' => $c->id, 'title' => 'L', 'duration_sec' => $d]);
            $l->tenant_id = $this->tenant->id;
            $l->save();
        }

        return $c;
    }

    private function sectionOfType(array $sections, string $type): ?array
    {
        foreach ($sections as $s) {
            if ($s['type'] === $type) {
                return $s;
            }
        }

        return null;
    }

    public function test_public_landing_resolves_layout_nav_courses_and_reviews(): void
    {
        $course = $this->publishedCourse();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);

        // A review (seeded directly — the write path is covered separately).
        $r = new Review(['course_id' => $course->id, 'user_id' => $student->id, 'rating' => 5, 'comment' => 'Great course']);
        $r->tenant_id = $this->tenant->id;
        $r->save();

        // Public (unauthenticated) — no default profile → resolver uses defaults().
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')
            ->assertOk()
            ->assertJsonPath('data.layout', 'classic')
            ->json('data');

        $this->assertNotEmpty($data['nav']['links']);

        $courses = $this->sectionOfType($data['sections'], 'courses');
        $this->assertNotNull($courses);
        $item = collect($courses['items'])->firstWhere('slug', $course->slug);
        $this->assertNotNull($item);
        $this->assertSame(2, $item['lessons_count']);
        $this->assertSame('25m', $item['duration_label']);
        $this->assertSame(1, $item['students_count']);
        $this->assertEquals(5.0, $item['rating']); // JSON serializes 5.0 as 5
        $this->assertSame('online', $item['type']);
        $this->assertFalse($item['enrolled']); // unauthenticated

        $reviews = $this->sectionOfType($data['sections'], 'testimonials');
        $this->assertNotNull($reviews);
        $this->assertSame('Great course', $reviews['items'][0]['comment']);
        $this->assertSame($course->title, $reviews['items'][0]['course_title']);
    }

    public function test_authenticated_landing_flags_enrolled_courses(): void
    {
        $course = $this->publishedCourse();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        $courses = $this->sectionOfType($data['sections'], 'courses');
        $item = collect($courses['items'])->firstWhere('slug', $course->slug);
        $this->assertTrue($item['enrolled']);
    }

    public function test_only_enrolled_student_can_review_and_review_is_upserted(): void
    {
        $course = $this->publishedCourse();
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($student);
        // Not enrolled → 403.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/courses/{$course->slug}/reviews", ['rating' => 4])
            ->assertStatus(403);

        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/courses/{$course->slug}/reviews", ['rating' => 4, 'comment' => 'good'])
            ->assertStatus(201)->assertJsonPath('data.rating', 4);

        // Second submit updates the same row (one review per student per course).
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/courses/{$course->slug}/reviews", ['rating' => 5, 'comment' => 'even better'])
            ->assertStatus(201)->assertJsonPath('data.rating', 5);

        $this->assertSame(1, Review::withoutGlobalScopes()->where('course_id', $course->id)->where('user_id', $student->id)->count());
    }
}
