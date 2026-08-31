<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * Assistant management + granular RBAC (M18): teacher CRUD over assistants, and
 * per-permission enforcement of the shared teacher surface.
 */
class AssistantManagementTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTenantRoles;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);

        // An assistant must be assigned to at least one academic year (the years
        // they may author in), so every academy needs one before assistants can be
        // created — mirrors the panel, which always has an active year.
        $this->year = new AcademicYear(['name' => 'الثالث الثانوي', 'sort_order' => 0]);
        $this->year->tenant_id = $this->tenant->id; // no request context in tests
        $this->year->save();

        // A stand-in route on the shared teacher/assistant surface, gated the way
        // every real route is now gated (M20): `can:<action key>` through the Gate,
        // behind the same stack (tenant → auth → active → role).
        Route::prefix('api/v1')
            ->middleware(['tenant', 'auth:sanctum', 'active', 'role:teacher,assistant', 'can:finance.receipts.review'])
            ->get('__test_finance', fn () => response()->json(['ok' => true]));
    }

    private function member(TenantUserRole $role, array $permissions = [], ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        // Authority comes from a role, never from the membership row (M20).
        if ($permissions !== []) {
            $this->grantPermissions($user, $tenant, $this->expandPermissions($permissions));
        }

        return $user;
    }

    public function test_teacher_creates_an_assistant_with_roles_and_gets_a_temp_password(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $role = $this->tenantRole($this->tenant, 'students_manager');

        $res = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/assistants', [
            'name' => 'Omar', 'phone' => '01099999999', 'role_uuids' => [$role->uuid],
            'academic_year_ids' => [$this->year->uuid],
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Omar')
            ->assertJsonPath('data.roles', [$role->name])
            ->assertJsonPath('data.status', 'active');

        $this->assertNotEmpty($res->json('data.temporary_password'));
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id, 'role' => 'assistant', 'status' => 'active',
        ]);
    }

    public function test_assistant_reaches_only_granted_surfaces(): void
    {
        // Granted `students`, NOT `centers`.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    public function test_assistant_with_no_permissions_is_forbidden_everywhere_shared(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, []));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/v1/teacher/centers')->assertStatus(403);
    }

    public function test_assistant_without_team_keys_cannot_manage_other_assistants(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));

        // Team management is delegatable now, but only to someone holding the key.
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/teacher/assistants')->assertStatus(403);
    }

    public function test_me_exposes_the_roles_held_here_and_the_keys_they_add_up_to(): void
    {
        $assistant = $this->member(TenantUserRole::Assistant, ['support']);
        Sanctum::actingAs($assistant);

        $current = $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.current.role', 'assistant')
            ->json('data.current');

        $this->assertContains(RoleTemplateKey::Assistant->label(), $current['roles']);
        $this->assertContains('support.reply', $current['permissions']);
        $this->assertNotContains('students.view', $current['permissions']);

        // The owner holds everything because the OWNER ROLE carries everything —
        // not because the code makes an exception for teachers.
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $current = $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me')->json('data.current');

        $this->assertSame([RoleTemplateKey::Teacher->label()], $current['roles']);
        $this->assertContains('students.view', $current['permissions']);
        $this->assertContains('centers.view', $current['permissions']);
    }

    public function test_teacher_can_rescope_roles_and_suspend(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $assistant = $this->member(TenantUserRole::Assistant, ['students']);
        $finance = $this->tenantRole($this->tenant, 'finance');
        Sanctum::actingAs($teacher);
        $h = ['X-Tenant' => 'demo'];

        $roles = $this->withHeaders($h)->patchJson("/api/v1/teacher/assistants/{$assistant->uuid}", [
            'role_uuids' => [$finance->uuid], 'status' => 'suspended',
        ])->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->json('data.roles');

        // Re-scoping REPLACES the granted roles; the baseline one always stays.
        $this->assertContains(RoleTemplateKey::Assistant->label(), $roles);
        $this->assertContains($finance->name, $roles);

        // Suspended → the assistant's access is cut immediately (active middleware).
        Sanctum::actingAs($assistant);
        $this->withHeaders($h)->getJson('/api/v1/teacher/students')->assertStatus(403);
    }

    public function test_max_assistants_limit_is_enforced(): void
    {
        $package = SubscriptionPackage::create([
            'slug' => 'solo', 'name' => 'Solo', 'price_minor' => 0,
            'interval' => 'monthly', 'trial_days' => 0, 'limits' => ['max_assistants' => 1],
        ]);
        app(SubscriptionService::class)->assign($this->tenant, $package);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        $y = ['academic_year_ids' => [$this->year->uuid]];
        $this->withHeaders($h)->postJson('/api/v1/teacher/assistants', ['name' => 'A', 'phone' => '01000000011'] + $y)
            ->assertStatus(201);
        $this->withHeaders($h)->postJson('/api/v1/teacher/assistants', ['name' => 'B', 'phone' => '01000000012'] + $y)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details.key', 'max_assistants');
    }

    public function test_cross_tenant_assistant_is_not_found(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignAssistant = $this->member(TenantUserRole::Assistant, ['students'], $other);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/teacher/assistants/{$foreignAssistant->uuid}")
            ->assertStatus(404);
    }

    public function test_student_cannot_manage_assistants(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/teacher/assistants')->assertStatus(403);
    }

    public function test_assistant_with_the_receipts_key_reaches_a_finance_gated_route(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['finance.receipts.review']));

        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/__test_finance')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_assistant_without_finance_permission_is_forbidden_on_a_finance_gated_route(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));

        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/__test_finance')->assertStatus(403);
    }

    public function test_the_owner_passes_the_finance_gate_through_their_role(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/__test_finance')->assertOk();
    }

    public function test_the_catalog_lists_grantable_action_keys(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $keys = $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/teacher/permissions')
            ->assertOk()
            ->json('data.*.key');

        $this->assertContains('finance.receipts.review', $keys);
        $this->assertContains('students.wallet.adjust', $keys);
        // Screen-level keys are gone: every entry is an action.
        $this->assertNotContains('finance', $keys);
    }

    public function test_create_and_update_persist_the_granted_roles(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);
        $h = ['X-Tenant' => 'demo'];

        $finance = $this->tenantRole($this->tenant, 'finance');
        $support = $this->tenantRole($this->tenant, 'support_agent');

        $uuid = $this->withHeaders($h)->postJson('/api/v1/teacher/assistants', [
            'name' => 'Finance Aide', 'phone' => '01055555555', 'role_uuids' => [$finance->uuid],
            'academic_year_ids' => [$this->year->uuid],
        ])->assertStatus(201)
            ->assertJsonPath('data.roles', [$finance->name])
            ->json('data.uuid');

        $roles = $this->withHeaders($h)->patchJson("/api/v1/teacher/assistants/{$uuid}", [
            'role_uuids' => [$finance->uuid, $support->uuid],
        ])->assertOk()->json('data.roles');

        $this->assertContains($finance->name, $roles);
        $this->assertContains($support->name, $roles);
    }

    public function test_creating_an_assistant_without_any_academic_year_is_rejected(): void
    {
        // An assistant with no year would be invisible on every year-scoped screen
        // (AssistantController::index filters by the active year), so the create
        // refuses it: pass `academic_year_ids`, or have an active year via the
        // X-Academic-Year header. Locks in the 422 the older tests tripped over.
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/assistants', [
            'name' => 'Yearless', 'phone' => '01088888888',
        ])->assertStatus(422);
    }

    public function test_the_active_year_header_is_used_when_no_year_is_passed(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $this->year->uuid])
            ->postJson('/api/v1/teacher/assistants', ['name' => 'Header Aide', 'phone' => '01077777777'])
            ->assertStatus(201);
    }

    /**
     * The tests were written against screen-level permissions ('students'); the
     * catalog is per-action now, so a coarse name expands to the keys that screen
     * actually needs. Fine keys pass through untouched.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function expandPermissions(array $names): array
    {
        $map = [
            'students' => ['students.view', 'students.create', 'students.update', 'students.delete',
                'students.import', 'students.export', 'students.reset_password',
                'students.enrollments.manage', 'students.content_overrides.manage',
                'students.wallet.view', 'students.wallet.adjust', 'students.activity.view',
                'students.notify', 'students.parents.manage'],
            'centers' => ['centers.view', 'centers.create', 'centers.update', 'centers.delete',
                'centers.sessions.manage', 'centers.attendance.view', 'centers.attendance.record',
                'centers.attendance.revoke', 'centers.activation_codes.view',
                'centers.activation_codes.issue', 'centers.activation_codes.disable',
                'centers.id_codes.manage', 'centers.exam_grades.manage'],
            'finance' => ['finance.receipts.review', 'finance.coupons.manage',
                'finance.subscription.view'],
            'support' => ['support.view', 'support.reply', 'support.status.change'],
            'homework' => ['exams.view', 'exams.submissions.view', 'exams.grade',
                'exams.pass_override'],
            'content' => ['content.view', 'content.lessons.create', 'content.lessons.update',
                'content.lessons.delete', 'content.lesson_sections.manage',
                'content.lesson_attachments.manage', 'content.lesson_availability.manage',
                'content.packages.manage', 'content.package_types.manage',
                'content.academic_years.manage', 'content.media.upload', 'content.media.manage'],
        ];

        $keys = [];

        foreach ($names as $name) {
            foreach ($map[$name] ?? [$name] as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
