<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Lesson;
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

    private function lesson(): Lesson
    {
        $l = new Lesson(['title' => 'Fav Lesson', 'visibility' => ContentVisibility::Visible->value]);
        $l->tenant_id = $this->tenant->id;
        $l->save(); // academic_year_id auto-filled by Lesson::booted()

        return $l;
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
        $lesson = $this->lesson();
        Sanctum::actingAs($this->student());

        $this->withHeaders($this->h)->postJson('/api/v1/me/favorites', ['target_type' => 'lesson', 'target_id' => $lesson->id])
            ->assertStatus(201)->assertJsonPath('data.favorited', true);

        $this->withHeaders($this->h)->getJson('/api/v1/me/favorites')
            ->assertOk()
            ->assertJsonPath('data.0.target_type', 'lesson')
            ->assertJsonPath('data.0.target_id', $lesson->id);

        $this->withHeaders($this->h)->deleteJson("/api/v1/me/favorites/lesson/{$lesson->id}")
            ->assertOk()->assertJsonPath('data.favorited', false);

        $this->withHeaders($this->h)->getJson('/api/v1/me/favorites')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
