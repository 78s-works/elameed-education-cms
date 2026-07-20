<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
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

    public function test_lesson_has_many_assets_and_one_video(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        $course = $this->makeCourse($tenant, ['visibility' => ContentVisibility::Visible->value]);
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        // The ONE video (also carries lesson_id, like the real upload flow).
        $video = new MediaAsset(['lesson_id' => $lesson->id, 'type' => MediaType::HlsVideo->value, 'status' => 'ready', 'title' => 'vid']);
        $video->tenant_id = $tenant->id;
        $video->save();
        $lesson->update(['video_asset_id' => $video->id]);

        // Two of the MANY assets (attachments) via the API.
        $this->withHeaders($h)->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", ['type' => 'link', 'title' => 'Slides', 'url' => 'https://ex.com/s'])->assertStatus(201);

        // Relations: attachments/assets exclude the video; video/videoAsset is the one video.
        $this->assertSame(1, $lesson->attachments()->count());   // the link only — NOT the video
        $this->assertSame(1, $lesson->assets()->count());
        $this->assertSame($video->id, $lesson->videoAsset->id);
        $this->assertSame($video->id, $lesson->video->id);

        // API: the lesson exposes `video` (one) separately from `attachments` (many).
        $row = $this->withHeaders($h)->getJson("/api/v1/teacher/units/{$unit->id}/lessons")->assertOk()->json('data.0');
        $this->assertTrue($row['has_video']);
        $this->assertSame('hls_video', $row['video']['type']);
        $this->assertCount(1, $row['attachments']);
        $this->assertNotContains('hls_video', array_column($row['attachments'], 'type'));
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

    public function test_course_descriptive_fields_persist_and_show_in_public_detail(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        $slug = $this->withHeaders($h)->postJson('/api/v1/teacher/courses', [
            'title' => 'Physics',
            'subtitle' => 'Mechanics for beginners',
            'visibility' => 'visible',
            'learning_outcomes' => ['Understand forces', 'Solve motion problems'],
            'requirements' => ['Basic algebra'],
            'audience' => ['Grade 10 students'],
            'parts' => [['title' => 'Kinematics', 'lessons_count' => 6, 'duration_min' => 90]],
            'promo_video_url' => 'https://youtu.be/demo',
        ])->assertStatus(201)
            ->assertJsonPath('data.subtitle', 'Mechanics for beginners')
            ->assertJsonPath('data.promo_video_url', 'https://youtu.be/demo')
            ->json('data.slug');

        // Public course detail exposes the rich marketing fields.
        $this->withHeaders($h)->getJson("/api/v1/courses/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.subtitle', 'Mechanics for beginners')
            ->assertJsonPath('data.learning_outcomes.0', 'Understand forces')
            ->assertJsonPath('data.parts.0.title', 'Kinematics')
            ->assertJsonPath('data.promo_video_url', 'https://youtu.be/demo');
    }

    public function test_course_has_its_own_thumbnail_distinct_from_cover(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        $slug = $this->withHeaders($h)->postJson('/api/v1/teacher/courses', [
            'title' => 'Physics',
            'visibility' => 'visible',
            'cover_url' => 'https://cdn.example.com/cover.jpg',
            'thumbnail_url' => 'https://cdn.example.com/thumb.jpg',
        ])->assertStatus(201)
            ->assertJsonPath('data.cover_url', 'https://cdn.example.com/cover.jpg')
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example.com/thumb.jpg')
            ->json('data.slug');

        // Public catalogue card + detail both expose the course's own thumbnail.
        $this->withHeaders($h)->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('data.0.thumbnail_url', 'https://cdn.example.com/thumb.jpg');

        $this->withHeaders($h)->getJson("/api/v1/courses/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example.com/thumb.jpg');
    }

    public function test_thumbnail_url_must_be_a_valid_url(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/courses', [
            'title' => 'Bad thumb',
            'thumbnail_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
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
