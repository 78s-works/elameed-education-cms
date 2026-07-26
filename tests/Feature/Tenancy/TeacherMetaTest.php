<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherMeta;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherMetaTest extends TestCase
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

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function asTeacher(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
    }

    public function test_teacher_can_create_a_meta_entry(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', [
            'group' => 'seo',
            'key' => 'description',
            'value' => 'Best physics academy',
            'sort_order' => 1,
        ])->assertStatus(201)
            ->assertJsonPath('data.group', 'seo')
            ->assertJsonPath('data.key', 'description')
            ->assertJsonPath('data.value', 'Best physics academy')
            ->assertJsonPath('data.sort_order', 1);

        $this->assertDatabaseHas('teacher_meta', [
            'tenant_id' => $this->tenant->id,
            'group' => 'seo',
            'key' => 'description',
            'value' => 'Best physics academy',
        ]);
    }

    public function test_group_defaults_to_general_when_omitted(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', [
            'key' => 'author',
            'value' => 'Mr. Adel',
        ])->assertStatus(201)
            ->assertJsonPath('data.group', 'general');
    }

    public function test_index_lists_entries_and_filters_by_group(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'description', 'value' => 'a'])->assertStatus(201);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'og', 'key' => 'og:image', 'value' => 'b'])->assertStatus(201);

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/meta')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/meta?group=seo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'description');
    }

    public function test_teacher_can_update_and_delete_an_entry(): void
    {
        $this->asTeacher();

        $id = $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'description', 'value' => 'old'])
            ->assertStatus(201)->json('data.id');

        $this->withHeaders($this->h)->putJson("/api/v1/teacher/meta/{$id}", ['key' => 'description', 'group' => 'seo', 'value' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.value', 'new');

        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/meta/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('teacher_meta', ['id' => $id]);
    }

    public function test_key_is_required(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['value' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.key.0', fn ($m) => $m !== null);
    }

    public function test_duplicate_key_in_same_group_is_rejected(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'description', 'value' => 'a'])->assertStatus(201);

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'description', 'value' => 'b'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.key.0', fn ($m) => $m !== null);
    }

    public function test_same_key_is_allowed_in_a_different_group(): void
    {
        $this->asTeacher();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'title', 'value' => 'a'])->assertStatus(201);
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'og', 'key' => 'title', 'value' => 'b'])->assertStatus(201);
    }

    public function test_update_can_keep_its_own_key_without_a_unique_conflict(): void
    {
        $this->asTeacher();

        $id = $this->withHeaders($this->h)->postJson('/api/v1/teacher/meta', ['group' => 'seo', 'key' => 'description', 'value' => 'a'])
            ->assertStatus(201)->json('data.id');

        // Same key, changed value → the unique rule must ignore this same row.
        $this->withHeaders($this->h)->putJson("/api/v1/teacher/meta/{$id}", ['group' => 'seo', 'key' => 'description', 'value' => 'a2'])
            ->assertOk()
            ->assertJsonPath('data.value', 'a2');
    }

    public function test_entries_are_tenant_isolated(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreign = new TeacherMeta(['group' => 'seo', 'key' => 'description', 'value' => 'secret']);
        $foreign->tenant_id = $other->id;
        $foreign->save();

        $this->asTeacher();

        // Not listed for this tenant.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/meta')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Not addressable across tenants → 404 (scoped route-model binding).
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/meta/{$foreign->id}")->assertNotFound();
        $this->withHeaders($this->h)->putJson("/api/v1/teacher/meta/{$foreign->id}", ['key' => 'description'])->assertNotFound();
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/meta/{$foreign->id}")->assertNotFound();
    }

    public function test_student_cannot_manage_meta(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/meta')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unauthenticated_cannot_manage_meta(): void
    {
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/meta')->assertStatus(401);
    }
}
