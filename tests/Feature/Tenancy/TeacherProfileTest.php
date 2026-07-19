<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherProfileTest extends TestCase
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

    private function makeMember(Tenant $tenant, TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function makeProfile(Tenant $tenant, array $attrs): TeacherProfile
    {
        $profile = new TeacherProfile($attrs);
        $profile->tenant_id = $tenant->id; // no request context in tests
        $profile->save();

        return $profile;
    }

    public function test_teacher_can_view_and_update_profile(): void
    {
        $tenant = $this->makeTenant('demo');
        $teacher = $this->makeMember($tenant, TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/profile', [
            'primary_color' => '#1D4ED8',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'contact' => ['phone' => '01000000000'],
        ])->assertOk()->assertJsonPath('data.primary_color', '#1D4ED8');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#1D4ED8')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.com/logo.png');
    }

    public function test_invalid_color_is_rejected(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/profile', [
            'primary_color' => 'red',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_student_cannot_access_teacher_profile(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Student));

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unauthenticated_cannot_access_teacher_profile(): void
    {
        $this->makeTenant('demo');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/profile')
            ->assertStatus(401);
    }

    /**
     * The core isolation guarantee — on MySQL the BelongsToTenant scope is the
     * ONLY guard, so this must hold at both the HTTP and the model layer.
     */
    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');
        $this->makeProfile($tenantA, ['primary_color' => '#AAAAAA']);
        $this->makeProfile($tenantB, ['primary_color' => '#BBBBBB']);

        $teacherA = $this->makeMember($tenantA, TenantUserRole::Teacher);

        // HTTP layer: teacher A on tenant A's host only ever sees A's profile.
        Sanctum::actingAs($teacherA);
        $this->withHeader('X-Tenant', 'alpha')->getJson('/api/v1/teacher/profile')
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#AAAAAA');

        // Model layer: the global scope filters strictly by the current tenant.
        $context = app(TenantContext::class);

        $context->setTenant($tenantA);
        $this->assertSame(['#AAAAAA'], TeacherProfile::query()->pluck('primary_color')->all());

        $context->setTenant($tenantB);
        $this->assertSame(['#BBBBBB'], TeacherProfile::query()->pluck('primary_color')->all());
    }

    public function test_landing_authoring_saves_layout_and_typed_sections(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'layout' => 'grid',
            'locales' => ['ar', 'en'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'hero', 'type' => 'hero', 'visible' => true, 'order' => 1,
                    'content' => [
                        'ar' => ['eyebrow' => 'أهلاً', 'title_html' => 'تعلّم <span>بسرعة</span>'],
                        'en' => ['eyebrow' => 'Hi', 'title_html' => 'Learn <span>fast</span>'],
                    ]],
                ['key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 2,
                    'content' => ['ar' => ['title' => 'كورساتي'], 'en' => ['title' => 'My courses']],
                    'config' => ['source' => 'all', 'limit' => 8]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.layout', 'grid')
            ->assertJsonPath('data.primary_locale', 'ar')
            ->assertJsonPath('data.locales', ['ar', 'en'])
            ->assertJsonPath('data.sections.0.content.en.eyebrow', 'Hi')
            ->assertJsonPath('data.sections.0.content.ar.eyebrow', 'أهلاً')
            ->assertJsonPath('data.sections.1.config.source', 'all');

        // GET authoring reflects the save (config on dynamic sections, no items).
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/landing')
            ->assertOk()
            ->assertJsonPath('data.layout', 'grid')
            ->assertJsonPath('data.sections.0.content.en.eyebrow', 'Hi')
            ->assertJsonPath('data.sections.1.config.limit', 8);
    }

    public function test_landing_rejects_unknown_type_bad_layout_and_bad_config(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        // Unknown section type.
        $this->withHeaders($h)->putJson('/api/v1/teacher/landing', [
            'sections' => [['key' => 'x', 'type' => 'bogus', 'visible' => true]],
        ])->assertStatus(422);

        // Invalid layout.
        $this->withHeaders($h)->putJson('/api/v1/teacher/landing', [
            'layout' => 'layout_9',
            'sections' => [['key' => 'hero', 'type' => 'hero', 'visible' => true]],
        ])->assertStatus(422);

        // Dynamic config: bad source + out-of-range limit.
        $this->withHeaders($h)->putJson('/api/v1/teacher/landing', [
            'sections' => [['key' => 'courses', 'type' => 'courses', 'visible' => true,
                'config' => ['source' => 'nope', 'limit' => 99]]],
        ])->assertStatus(422);
    }

    public function test_hero_title_html_is_sanitized_to_span_only(): void
    {
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $html = $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'sections' => [['key' => 'hero', 'type' => 'hero', 'visible' => true,
                'content' => ['ar' => ['title_html' => 'Hi <span onclick="x()">there</span><script>alert(1)</script>']]]],
        ])->assertOk()->json('data.sections.0.content.ar.title_html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('<span>there</span>', $html);
    }

    public function test_landing_media_upload_returns_public_url(): void
    {
        Storage::fake('public');
        $tenant = $this->makeTenant('demo');
        Sanctum::actingAs($this->makeMember($tenant, TenantUserRole::Teacher));

        $res = $this->withHeader('X-Tenant', 'demo')->post('/api/v1/teacher/landing/media', [
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($res->json('data.url'));
    }
}
