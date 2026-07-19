<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-language landing: per-locale content round-trips, add/remove language,
 * public fallback to the primary locale, duplicate (add) sections with unique
 * keys, and context reflecting the tenant's enabled languages.
 */
class LandingTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function actingTeacher(): void
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
        Sanctum::actingAs($u);
    }

    private function profile(array $attrs): TeacherProfile
    {
        $p = new TeacherProfile($attrs);
        $p->tenant_id = $this->tenant->id; // no request context here
        $p->save();

        return $p;
    }

    public function test_put_get_round_trips_per_locale_content(): void
    {
        $this->actingTeacher();

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar', 'en'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'من نحن'], 'en' => ['title' => 'About us']]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.locales', ['ar', 'en'])
            ->assertJsonPath('data.sections.0.content.ar.title', 'من نحن')
            ->assertJsonPath('data.sections.0.content.en.title', 'About us');

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/landing')
            ->assertOk()
            ->assertJsonPath('data.sections.0.content.en.title', 'About us');
    }

    public function test_public_landing_returns_all_locales_with_primary_fallback(): void
    {
        // 'about' has only Arabic → English must fall back to Arabic on the public payload.
        $this->profile([
            'locales' => ['ar', 'en'],
            'primary_locale' => 'ar',
            'landing_sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'من نحن', 'body' => 'نص عربي']]],
            ],
        ]);

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')
            ->assertOk()
            ->assertJsonPath('data.primary_locale', 'ar')
            ->assertJsonPath('data.locales', ['ar', 'en'])
            ->json('data');

        $about = collect($data['sections'])->firstWhere('type', 'about');
        $this->assertSame('من نحن', $about['content']['ar']['title']);
        // English missing → filled from the primary (Arabic).
        $this->assertSame('من نحن', $about['content']['en']['title']);
    }

    public function test_removing_a_language_drops_its_content(): void
    {
        $this->actingTeacher();

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar', 'en'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'عربي'], 'en' => ['title' => 'English']]],
            ],
        ])->assertOk()->assertJsonPath('data.sections.0.content.en.title', 'English');

        // Drop English.
        $res = $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'عربي']]],
            ],
        ])->assertOk()->assertJsonPath('data.locales', ['ar']);

        $content = $res->json('data.sections.0.content');
        $this->assertArrayHasKey('ar', $content);
        $this->assertArrayNotHasKey('en', $content);
    }

    public function test_duplicate_section_type_gets_unique_key(): void
    {
        $this->actingTeacher();

        $res = $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'First']]],
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 2,
                    'content' => ['ar' => ['title' => 'Second']]],
            ],
        ])->assertOk();

        $keys = collect($res->json('data.sections'))->pluck('key')->all();
        $this->assertSame(['about', 'about-2'], $keys);
    }

    public function test_context_reflects_tenant_locales(): void
    {
        $this->profile(['locales' => ['ar', 'en'], 'primary_locale' => 'ar']);

        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/context')
            ->assertOk()
            ->assertJsonPath('data.locale.default', 'ar')
            ->assertJsonPath('data.locale.supported', ['ar', 'en']);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->actingTeacher();

        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar', 'fr'], // fr is not platform-supported
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'x']]],
            ],
        ])->assertStatus(422);
    }
}
