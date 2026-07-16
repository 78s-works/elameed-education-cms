<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the tenancy-endpoint improvements: published-only selected courses,
 * SVG upload rejection, optimistic concurrency (ETag/If-Match), and the
 * context ETag / 304 revalidation.
 */
class EndpointImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function teacher(): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function course(string $title, ContentVisibility $visibility): Course
    {
        $c = new Course(['title' => $title, 'visibility' => $visibility->value, 'price_minor' => 1000, 'is_free' => false]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = strtolower($title).'-'.uniqid();
        $c->save();

        return $c;
    }

    public function test_selected_source_excludes_unpublished_courses_from_public_landing(): void
    {
        $published = $this->course('Published', ContentVisibility::Visible);
        $hidden = $this->course('Hidden', ContentVisibility::Hidden);

        $profile = new TeacherProfile([
            'layout' => 'classic',
            'landing_sections' => [[
                'key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 1,
                'content' => ['title' => 'Courses'],
                'config' => ['source' => 'selected', 'course_ids' => [$published->id, $hidden->id], 'limit' => 6],
            ]],
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')
            ->assertOk()->json('data');

        $courses = collect($data['sections'])->firstWhere('type', 'courses');
        $slugs = collect($courses['items'])->pluck('slug');

        $this->assertTrue($slugs->contains($published->slug), 'published course should appear');
        $this->assertFalse($slugs->contains($hidden->slug), 'unpublished selected course must NOT leak');
    }

    public function test_media_upload_accepts_png_but_rejects_svg(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->teacher());

        $this->withHeader('X-Tenant', 'demo')
            ->post('/api/v1/teacher/landing/media', ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['data' => ['url']]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>';
        $this->withHeader('X-Tenant', 'demo')
            ->post('/api/v1/teacher/landing/media', ['file' => UploadedFile::fake()->createWithContent('x.svg', $svg)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_profile_put_enforces_optional_if_match(): void
    {
        Sanctum::actingAs($this->teacher());

        $etag = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        // Matching If-Match → 200, and the version advances.
        $newEtag = $this->withHeaders(['X-Tenant' => 'demo', 'If-Match' => $etag])
            ->putJson('/api/v1/teacher/profile', ['bio' => 'hello'])
            ->assertOk()->headers->get('ETag');
        $this->assertNotSame($etag, $newEtag);

        // Stale If-Match → 412.
        $this->withHeaders(['X-Tenant' => 'demo', 'If-Match' => $etag])
            ->putJson('/api/v1/teacher/profile', ['bio' => 'conflict'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'precondition_failed');

        // No If-Match → still allowed (backward compatible). flushHeaders() clears
        // the persisted If-Match from the prior request so this one sends none.
        $this->flushHeaders();
        $this->withHeader('X-Tenant', 'demo')
            ->putJson('/api/v1/teacher/profile', ['bio' => 'no-precondition'])
            ->assertOk();
    }

    public function test_context_returns_etag_and_304_on_revalidation(): void
    {
        $etag = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/context')
            ->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders(['X-Tenant' => 'demo', 'If-None-Match' => $etag])
            ->getJson('/api/v1/tenant/context')
            ->assertStatus(304);
    }
}
