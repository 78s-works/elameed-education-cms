<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
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

/**
 * Public course catalogue + lesson media relations. Teacher course/unit authoring
 * is retired (VD §7 / VD-D1b — packages replace them), so courses are created
 * directly here; only the public GET /courses surface remains an endpoint.
 */
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

    private function makeLesson(Tenant $tenant, Course $course, array $attrs = []): Lesson
    {
        $lesson = new Lesson(array_merge(['course_id' => $course->id, 'title' => 'L'], $attrs));
        $lesson->tenant_id = $tenant->id;
        $lesson->save();

        return $lesson;
    }

    private function makeYear(Tenant $tenant, string $name = 'Default'): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $tenant->id;
        $year->save();

        return $year;
    }

    private function makePackage(Tenant $tenant, array $attrs = []): Package
    {
        $package = new Package(array_merge(['name' => 'Pack '.uniqid()], $attrs));
        $package->tenant_id = $tenant->id;
        // academic_year_id is NOT NULL; fall back to (or create) a Default year.
        $package->academic_year_id = $attrs['academic_year_id']
            ?? AcademicYear::where('tenant_id', $tenant->id)->orderBy('id')->value('id')
            ?? $this->makeYear($tenant)->id;
        $package->save();

        return $package;
    }

    public function test_lesson_has_many_assets_and_one_video(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        $course = $this->makeCourse($tenant, ['visibility' => ContentVisibility::Visible->value]);
        $lesson = $this->makeLesson($tenant, $course);

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
        // Lessons are standalone now — read via the year-scoped show endpoint.
        $year = AcademicYear::where('tenant_id', $tenant->id)->firstOrFail();
        $row = $this->withHeaders($h + ['X-Academic-Year' => $year->uuid])
            ->getJson("/api/v1/teacher/lessons/{$lesson->id}")->assertOk()->json('data');
        $this->assertTrue($row['has_video']);
        $this->assertSame('hls_video', $row['video']['type']);
        $this->assertCount(1, $row['attachments']);
        $this->assertNotContains('hls_video', array_column($row['attachments'], 'type'));
    }

    public function test_course_descriptive_fields_show_in_public_detail(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];

        $course = $this->makeCourse($tenant, [
            'subtitle' => 'Mechanics for beginners',
            'learning_outcomes' => ['Understand forces', 'Solve motion problems'],
            'requirements' => ['Basic algebra'],
            'audience' => ['Grade 10 students'],
            'parts' => [['title' => 'Kinematics', 'lessons_count' => 6, 'duration_min' => 90]],
            'promo_video_url' => 'https://youtu.be/demo',
        ]);

        // Public course detail exposes the rich marketing fields.
        $this->withHeaders($h)->getJson("/api/v1/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.subtitle', 'Mechanics for beginners')
            ->assertJsonPath('data.learning_outcomes.0', 'Understand forces')
            ->assertJsonPath('data.parts.0.title', 'Kinematics')
            ->assertJsonPath('data.promo_video_url', 'https://youtu.be/demo');
    }

    public function test_course_has_its_own_thumbnail_distinct_from_cover(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];

        $course = $this->makeCourse($tenant, [
            'cover_url' => 'https://cdn.example.com/cover.jpg',
            'thumbnail_url' => 'https://cdn.example.com/thumb.jpg',
        ]);

        // Public catalogue card + detail both expose the course's own thumbnail.
        $this->withHeaders($h)->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('data.0.thumbnail_url', 'https://cdn.example.com/thumb.jpg');

        $this->withHeaders($h)->getJson("/api/v1/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example.com/thumb.jpg');
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
        $this->makeCourse($tenant, ['visibility' => ContentVisibility::Hidden->value, 'slug' => 'hidden-course']);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/courses/hidden-course')
            ->assertStatus(404);
    }

    public function test_public_course_detail_lists_its_published_lessons(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];

        $course = $this->makeCourse($tenant, ['slug' => 'phys-101']);
        $this->makeLesson($tenant, $course, ['title' => 'Intro', 'sort_order' => 0, 'visibility' => ContentVisibility::Visible->value]);
        $this->makeLesson($tenant, $course, ['title' => 'Hidden', 'sort_order' => 1, 'visibility' => ContentVisibility::Hidden->value]);

        // Units retired → the detail exposes a flat, published lessons list.
        $this->withHeaders($h)->getJson('/api/v1/courses/phys-101')
            ->assertOk()
            ->assertJsonCount(1, 'data.lessons')
            ->assertJsonPath('data.lessons.0.title', 'Intro');
    }

    public function test_attachment_link_can_be_added_to_a_lesson(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeTeacher($tenant));
        $h = ['X-Tenant' => 'demo'];

        // Set the tenant context so BelongsToTenant auto-fills tenant_id on create.
        app(TenantContext::class)->setTenant($tenant);
        $course = $this->makeCourse($tenant);
        $lesson = $this->makeLesson($tenant, $course, ['title' => 'L1']);

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

    // ── B19: view=lessons|packages + access_mode filter ────────────────────────

    public function test_catalogue_lessons_view_lists_only_published_purchasable_lessons(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];
        $course = $this->makeCourse($tenant);

        $this->makeLesson($tenant, $course, ['title' => 'Sellable', 'is_purchasable' => true, 'visibility' => ContentVisibility::Visible->value]);
        $this->makeLesson($tenant, $course, ['title' => 'NotSellable', 'is_purchasable' => false, 'visibility' => ContentVisibility::Visible->value]);
        $this->makeLesson($tenant, $course, ['title' => 'HiddenSellable', 'is_purchasable' => true, 'visibility' => ContentVisibility::Hidden->value]);

        $names = collect($this->withHeaders($h)->getJson('/api/v1/courses?view=lessons')
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Sellable'], $names); // purchasable + published only
    }

    public function test_catalogue_packages_view_lists_only_purchasable_packages(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];

        $this->makePackage($tenant, ['name' => 'Sellable Pack', 'is_purchasable' => true]);
        $this->makePackage($tenant, ['name' => 'Locked Pack', 'is_purchasable' => false]);

        $names = collect($this->withHeaders($h)->getJson('/api/v1/courses?view=packages')
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Sellable Pack'], $names);
    }

    public function test_catalogue_access_mode_filter_center_includes_both_excludes_online(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];
        $course = $this->makeCourse($tenant);

        foreach (['center', 'online', 'both'] as $mode) {
            $this->makeLesson($tenant, $course, ['title' => ucfirst($mode), 'is_purchasable' => true, 'access_mode' => $mode]);
        }

        $names = collect($this->withHeaders($h)->getJson('/api/v1/courses?view=lessons&access_mode=center')
            ->assertOk()->json('data'))->pluck('name')->all();

        sort($names);
        $this->assertSame(['Both', 'Center'], $names); // `both` is a wildcard; online excluded
    }

    public function test_catalogue_scopes_to_a_center_students_channel_ignoring_the_query(): void
    {
        // A single-channel student's study_mode is authoritative: online content is
        // hidden from a center student even if the request forges ?access_mode=online.
        $tenant = $this->makeTenant('demo');
        $course = $this->makeCourse($tenant);
        foreach (['center', 'online', 'both'] as $mode) {
            $this->makeLesson($tenant, $course, ['title' => ucfirst($mode), 'is_purchasable' => true, 'access_mode' => $mode]);
        }

        $student = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);
        $profile = new StudentProfile(['study_mode' => 'center']);
        $profile->tenant_id = $tenant->id;
        $profile->user_id = $student->id;
        $profile->save();

        Sanctum::actingAs($student);

        $names = collect($this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/courses?view=lessons&access_mode=online')
            ->assertOk()->json('data'))->pluck('name')->all();

        sort($names);
        $this->assertSame(['Both', 'Center'], $names); // online excluded despite the query
    }

    public function test_catalogue_pins_a_student_to_their_profile_year_ignoring_the_query(): void
    {
        // A logged-in student's catalogue is server-scoped to their profile's year;
        // a forged ?academic_year for another year is ignored.
        $tenant = $this->makeTenant('demo');
        $course = $this->makeCourse($tenant);
        $yearA = $this->makeYear($tenant, 'Year A');
        $yearB = $this->makeYear($tenant, 'Year B');
        $this->makeLesson($tenant, $course, ['title' => 'In A', 'is_purchasable' => true, 'academic_year_id' => $yearA->id]);
        $this->makeLesson($tenant, $course, ['title' => 'In B', 'is_purchasable' => true, 'academic_year_id' => $yearB->id]);

        $student = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $student->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);
        $profile = new StudentProfile(['academic_year_id' => $yearA->id, 'study_mode' => 'online']);
        $profile->tenant_id = $tenant->id;
        $profile->user_id = $student->id;
        $profile->save();

        Sanctum::actingAs($student);

        $names = collect($this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/courses?view=lessons&academic_year={$yearB->uuid}")
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['In A'], $names); // pinned to Year A; the Year B query is ignored
    }

    public function test_catalogue_academic_year_filter_narrows_lessons(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];
        $course = $this->makeCourse($tenant);

        $y1 = $this->makeYear($tenant, 'Year 1');
        $y2 = $this->makeYear($tenant, 'Year 2');
        $this->makeLesson($tenant, $course, ['title' => 'In Y1', 'is_purchasable' => true, 'academic_year_id' => $y1->id]);
        $this->makeLesson($tenant, $course, ['title' => 'In Y2', 'is_purchasable' => true, 'academic_year_id' => $y2->id]);

        $names = collect($this->withHeaders($h)->getJson("/api/v1/courses?view=lessons&academic_year={$y1->uuid}")
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['In Y1'], $names);
    }

    public function test_catalogue_default_view_still_returns_courses(): void
    {
        $tenant = $this->makeTenant('demo');
        $h = ['X-Tenant' => 'demo'];
        $this->makeCourse($tenant, ['title' => 'A Course', 'visibility' => ContentVisibility::Visible->value]);

        // No view param → the course catalogue, unchanged (backward compatible).
        $this->withHeaders($h)->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A Course');
    }

    public function test_catalogue_rejects_unknown_view(): void
    {
        $tenant = $this->makeTenant('demo');
        $this->makeCourse($tenant);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/courses?view=bundles')
            ->assertStatus(422);
    }
}
