<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\ParentMagicLink;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VD R11 — permanent, revocable passwordless parent magic links + multi-child
 * switcher. Token lifecycle (VD-D5 RESOLVED 2026-08-10): permanent + revocable,
 * hashed at rest, tenant-scoped, rate-limited. Reuse SUCCEEDS (permanent); a
 * revoked (is_active=false) or cross-tenant token is rejected as 404.
 */
class ParentMagicLinkTest extends TestCase
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

    private function member(TenantUserRole $role, array $attrs = [], ?Tenant $tenant = null, string $status = 'active'): User
    {
        $user = User::factory()->create($attrs);
        TenantUser::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => $status, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function link(User $parent, User $student, ?Tenant $tenant = null): void
    {
        $l = new ParentLink(['parent_user_id' => $parent->id, 'student_user_id' => $student->id, 'relation' => 'father']);
        $l->tenant_id = ($tenant ?? $this->tenant)->id;
        $l->save();
    }

    /** Seed a magic link directly (bypasses the teacher endpoint) and return the raw token. */
    private function issueToken(User $parent, ?Tenant $tenant = null, bool $active = true): string
    {
        $raw = bin2hex(random_bytes(24));
        $l = new ParentMagicLink(['parent_user_id' => $parent->id, 'token_hash' => ParentMagicLink::hash($raw), 'is_active' => $active]);
        $l->tenant_id = ($tenant ?? $this->tenant)->id;
        $l->save();

        return $raw;
    }

    public function test_magic_link_logs_the_parent_in_and_lists_children(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $kid = $this->member(TenantUserRole::Student, ['name' => 'My Kid']);
        $this->link($parent, $kid);
        $token = $this->issueToken($parent);

        $res = $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $parent->uuid)
            ->assertJsonPath('data.children.0.name', 'My Kid')
            ->assertJsonPath('data.active_child', $kid->uuid);

        // The issued session is a working parent bearer token.
        $bearer = $res->json('data.token');
        $this->assertNotEmpty($bearer);
        $this->withHeaders($this->h)->withToken($bearer)->getJson('/api/v1/parent/children')->assertOk();

        // Consuming stamped last_used_at; the link stays active (permanent).
        $this->assertDatabaseHas('parent_magic_links', [
            'parent_user_id' => $parent->id, 'is_active' => 1,
        ]);
        $this->assertNotNull(ParentMagicLink::query()->first()->last_used_at);
    }

    public function test_magic_link_is_permanent_and_reusable(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $this->link($parent, $this->member(TenantUserRole::Student));
        $token = $this->issueToken($parent);

        // Permanent: consuming twice both succeed (contrast with a single-use token).
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")->assertOk();
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")->assertOk();
    }

    public function test_revoked_link_is_rejected(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $this->link($parent, $this->member(TenantUserRole::Student));
        $token = $this->issueToken($parent, active: false);

        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")->assertNotFound();
    }

    public function test_magic_link_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);

        $parent = $this->member(TenantUserRole::Parent);
        $this->link($parent, $this->member(TenantUserRole::Student));
        $token = $this->issueToken($parent); // minted for tenant `demo`

        // Same token on another academy host resolves to nothing.
        $this->withHeaders(['X-Tenant' => 'other'])->getJson("/api/v1/parent/magic/{$token}")->assertNotFound();
        // Control: it still works on its own tenant.
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")->assertOk();

        // Guard the unused var so the linter/tenant creation isn't flagged.
        $this->assertSame('other', $other->slug);
    }

    public function test_parent_switches_active_child(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $a = $this->member(TenantUserRole::Student, ['name' => 'Kid A']);
        $b = $this->member(TenantUserRole::Student, ['name' => 'Kid B']);
        $this->link($parent, $a);
        $this->link($parent, $b);
        $other = $this->member(TenantUserRole::Student, ['name' => 'Not Mine']);

        $token = $this->issueToken($parent);
        $bearer = $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")
            ->assertOk()->json('data.token');

        // Switch to sibling B.
        $this->withHeaders($this->h)->withToken($bearer)
            ->postJson('/api/v1/parent/switch', ['student' => $b->uuid])
            ->assertOk()
            ->assertJsonPath('data.uuid', $b->uuid)
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('personal_access_tokens', ['active_child_id' => $b->id]);

        // /parent/children now marks B active, A inactive.
        $children = collect($this->withHeaders($this->h)->withToken($bearer)
            ->getJson('/api/v1/parent/children')->json('data'));
        $this->assertTrue($children->firstWhere('uuid', $b->uuid)['active']);
        $this->assertFalse($children->firstWhere('uuid', $a->uuid)['active']);

        // Cannot switch to a child that isn't linked.
        $this->withHeaders($this->h)->withToken($bearer)
            ->postJson('/api/v1/parent/switch', ['student' => $other->uuid])
            ->assertNotFound();
    }

    public function test_disabling_a_child_drops_it_from_the_list_but_the_token_stays(): void
    {
        $parent = $this->member(TenantUserRole::Parent);
        $active = $this->member(TenantUserRole::Student, ['name' => 'Active Kid']);
        $suspended = $this->member(TenantUserRole::Student, ['name' => 'Suspended Kid']);
        $this->link($parent, $active);
        $this->link($parent, $suspended);

        // Disable one child's membership.
        TenantUser::query()->where('user_id', $suspended->id)
            ->update(['status' => MembershipStatus::Suspended->value]);

        $token = $this->issueToken($parent);
        $names = collect($this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")
            ->assertOk()->json('data.children'))->pluck('name')->all();

        $this->assertSame(['Active Kid'], $names); // suspended child dropped
        // The token itself is untouched — still logs in.
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$token}")->assertOk();
    }

    public function test_teacher_issues_and_revokes_a_magic_link(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $parent = $this->member(TenantUserRole::Parent);
        $this->link($parent, $student);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $raw = $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$student->uuid}/parents/{$parent->uuid}/magic-link")
            ->assertStatus(201)
            ->json('data.magic_token');

        $this->assertNotEmpty($raw);
        // The freshly-issued link logs the guardian in.
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$raw}")->assertOk();

        // Revoke → the same token stops working.
        $this->withHeaders($this->h)
            ->deleteJson("/api/v1/teacher/students/{$student->uuid}/parents/{$parent->uuid}/magic-link")
            ->assertOk()->assertJsonPath('data.revoked', true);
        $this->withHeaders($this->h)->getJson("/api/v1/parent/magic/{$raw}")->assertNotFound();
    }

    public function test_teacher_cannot_issue_for_an_unlinked_parent(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $parent = $this->member(TenantUserRole::Parent); // never linked to this student

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/students/{$student->uuid}/parents/{$parent->uuid}/magic-link")
            ->assertNotFound();
    }
}
