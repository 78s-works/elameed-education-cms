<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherMeta;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TenantLandingMetaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function profile(Tenant $tenant, array $attrs): void
    {
        $profile = new TeacherProfile($attrs);
        $profile->tenant_id = $tenant->id;
        $profile->save();
    }

    private function meta(Tenant $tenant, string $group, string $key, ?string $value): void
    {
        $m = new TeacherMeta(['group' => $group, 'key' => $key, 'value' => $value]);
        $m->tenant_id = $tenant->id;
        $m->save();
    }

    public function test_returns_branding_and_grouped_meta_without_auth(): void
    {
        $this->profile($this->tenant, [
            'logo_url' => 'https://cdn.example.com/logo.png',
            'favicon_url' => 'https://cdn.example.com/favicon.ico',
            'primary_color' => '#1E88E5',
            'bio' => 'Physics academy',
            'socials' => ['facebook' => 'https://facebook.com/x'],
        ]);
        $this->meta($this->tenant, 'seo', 'description', 'Best physics academy');
        $this->meta($this->tenant, 'seo', 'keywords', 'physics,math');
        $this->meta($this->tenant, 'og', 'og:image', 'https://cdn.example.com/og.jpg');

        // No Sanctum::actingAs → proves the endpoint is public.
        $this->withHeaders($this->h)->getJson('/api/v1/tenant/landing/meta')
            ->assertOk()
            ->assertJsonPath('data.site.slug', 'demo')
            ->assertJsonPath('data.site.name', 'Demo')
            ->assertJsonPath('data.branding.logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('data.branding.favicon_url', 'https://cdn.example.com/favicon.ico')
            ->assertJsonPath('data.branding.primary_color', '#1E88E5')
            ->assertJsonPath('data.branding.socials.facebook', 'https://facebook.com/x')
            // Grouped by `group`, ordered by sort_order then key within a group.
            ->assertJsonCount(2, 'data.meta.seo')
            ->assertJsonCount(1, 'data.meta.og')
            ->assertJsonPath('data.meta.seo.0.key', 'description')
            ->assertJsonPath('data.meta.seo.1.key', 'keywords')
            ->assertJsonPath('data.meta.og.0.value', 'https://cdn.example.com/og.jpg');
    }

    public function test_meta_is_empty_object_and_branding_is_null_safe_when_unset(): void
    {
        $this->withHeaders($this->h)->getJson('/api/v1/tenant/landing/meta')
            ->assertOk()
            ->assertJsonPath('data.branding.logo_url', null)
            ->assertJsonPath('data.branding.socials', [])   // {} decodes to [] in an assoc-less compare
            ->assertJsonPath('data.meta', []);
    }

    public function test_carries_an_etag_and_revalidates_with_304(): void
    {
        $this->meta($this->tenant, 'seo', 'description', 'v1');

        $res = $this->withHeaders($this->h)->getJson('/api/v1/tenant/landing/meta')->assertOk();
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders($this->h + ['If-None-Match' => $etag])
            ->getJson('/api/v1/tenant/landing/meta')
            ->assertStatus(304);
    }

    public function test_etag_changes_when_metadata_changes(): void
    {
        $this->meta($this->tenant, 'seo', 'description', 'v1');
        $etag = $this->withHeaders($this->h)->getJson('/api/v1/tenant/landing/meta')->assertOk()->headers->get('ETag');

        // A new entry must bust the cache — the stale If-None-Match no longer matches.
        $this->meta($this->tenant, 'seo', 'keywords', 'k');

        $this->withHeaders($this->h + ['If-None-Match' => $etag])
            ->getJson('/api/v1/tenant/landing/meta')
            ->assertOk()
            ->assertJsonCount(2, 'data.meta.seo');
    }

    public function test_meta_is_tenant_isolated(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $this->meta($other, 'seo', 'description', 'secret');
        $this->meta($this->tenant, 'seo', 'description', 'mine');

        $this->withHeaders($this->h)->getJson('/api/v1/tenant/landing/meta')
            ->assertOk()
            ->assertJsonCount(1, 'data.meta.seo')
            ->assertJsonPath('data.meta.seo.0.value', 'mine');
    }
}
