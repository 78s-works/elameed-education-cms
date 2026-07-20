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
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
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
        $c = new Course(['title' => 'Algebra', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false, 'thumbnail_url' => 'https://cdn.example.com/thumb.jpg']);
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
        $this->assertSame('https://cdn.example.com/thumb.jpg', $item['thumbnail_url']);
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

    public function test_default_landing_carries_a_per_section_variant(): void
    {
        // No profile → resolver uses defaults(); every section must expose a variant.
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        $this->assertSame('split', $this->sectionOfType($data['sections'], 'hero')['variant']);
        $this->assertSame('grid', $this->sectionOfType($data['sections'], 'courses')['variant']);
    }

    public function test_stored_section_without_variant_defaults_to_type_default(): void
    {
        // A legacy section saved before variants existed (no `variant` key).
        $p = new TeacherProfile([
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'landing_sections' => [
                ['key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'الكورسات']],
                    'config' => ['source' => 'all', 'limit' => 6]],
            ],
        ]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        // courses default variant is the first in LandingSchema::VARIANTS['courses'].
        $this->assertSame('grid', $this->sectionOfType($data['sections'], 'courses')['variant']);
    }

    public function test_course_card_cover_falls_back_to_first_lesson_video_poster(): void
    {
        // A course with NO cover and NO thumbnail of its own.
        $c = new Course(['title' => 'NoImage', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 0, 'is_free' => true]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'no-image-'.uniqid();
        $c->save();

        $unit = new Unit(['course_id' => $c->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $c->id, 'title' => 'L', 'sort_order' => 1, 'visibility' => ContentVisibility::Visible->value]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $video = new MediaAsset(['lesson_id' => $lesson->id, 'type' => MediaType::HlsVideo->value, 'status' => 'ready', 'title' => 'v']);
        $video->tenant_id = $this->tenant->id;
        $video->thumbnail_url = 'https://cdn.example.com/lesson-poster.jpg';
        $video->save();
        $lesson->update(['video_asset_id' => $video->id]);

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');
        $item = collect($this->sectionOfType($data['sections'], 'courses')['items'])->firstWhere('slug', $c->slug);

        $this->assertNotNull($item);
        // cover_url falls back to the lesson video poster…
        $this->assertSame('https://cdn.example.com/lesson-poster.jpg', $item['cover_url']);
        // …while the course's OWN thumbnail stays null.
        $this->assertNull($item['thumbnail_url']);
    }

    public function test_course_card_cover_prefers_course_cover_url_over_fallbacks(): void
    {
        $c = new Course([
            'title' => 'HasCover', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 0, 'is_free' => true,
            'cover_url' => 'https://cdn.example.com/course-cover.jpg',
            'thumbnail_url' => 'https://cdn.example.com/course-thumb.jpg',
        ]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'has-cover-'.uniqid();
        $c->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');
        $item = collect($this->sectionOfType($data['sections'], 'courses')['items'])->firstWhere('slug', $c->slug);

        $this->assertSame('https://cdn.example.com/course-cover.jpg', $item['cover_url']);
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
