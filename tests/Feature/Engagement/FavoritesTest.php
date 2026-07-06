<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoritesTest extends TestCase
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

    private function course(): Course
    {
        $c = new Course(['title' => 'Fav Course', 'visibility' => ContentVisibility::Visible->value]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'fav-'.uniqid();
        $c->save();

        return $c;
    }

    private function student(): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    public function test_add_list_and_remove_favorite(): void
    {
        $course = $this->course();
        Sanctum::actingAs($this->student());

        $this->withHeaders($this->h)->postJson('/api/v1/me/favorites', ['course' => $course->uuid])
            ->assertStatus(201)->assertJsonPath('data.favorited', true);

        $this->withHeaders($this->h)->getJson('/api/v1/me/favorites')
            ->assertOk()->assertJsonPath('data.0.uuid', $course->uuid);

        $this->withHeaders($this->h)->deleteJson("/api/v1/me/favorites/{$course->uuid}")
            ->assertOk()->assertJsonPath('data.favorited', false);

        $this->withHeaders($this->h)->getJson('/api/v1/me/favorites')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
