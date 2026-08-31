<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The console's investigation surfaces: a filterable audit log (with a CSV of
 * exactly what is on screen) and a searchable tenant list. Both are unusable at
 * fifty academies without these, which is the point of the tickets behind them.
 */
class AdminConsoleFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug, string $name): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $name, 'status' => TenantStatus::Active]);
    }

    private function log(array $attrs): AuditLog
    {
        // created_at is not fillable on an append-only model — set it after.
        $createdAt = $attrs['created_at'] ?? now();
        unset($attrs['created_at']);

        $log = AuditLog::create(array_merge([
            'action' => 'order.refunded',
            'subject_type' => 'order',
            'subject_id' => 1,
            'meta' => [],
            'ip' => '10.0.0.1',
        ], $attrs));

        $log->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $log;
    }

    public function test_audit_log_filters_by_academy_action_date_and_free_text(): void
    {
        $admin = User::factory()->platformAdmin()->create(['name' => 'Nour']);
        Sanctum::actingAs($admin);

        $one = $this->tenant('ahmed', 'Ahmed Academy');
        $two = $this->tenant('mona', 'Mona Academy');

        $this->log(['tenant_id' => $one->id, 'actor_user_id' => $admin->id, 'subject_id' => 77]);
        $this->log(['tenant_id' => $two->id, 'action' => 'tenant.updated', 'subject_type' => 'tenant']);
        $this->log(['tenant_id' => $one->id, 'action' => 'tenant.updated', 'created_at' => now()->subMonths(2)]);

        // Academy, addressed by uuid the way the console's links are built.
        $this->getJson("/api/v1/admin/audit-logs?tenant={$one->uuid}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.tenant.name', 'Ahmed Academy');

        // Event type.
        $this->getJson('/api/v1/admin/audit-logs?action=order.refunded')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Date range excludes the two-month-old row.
        $this->getJson('/api/v1/admin/audit-logs?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Free text hits the actor's name.
        $this->getJson('/api/v1/admin/audit-logs?q=Nour')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // …and a subject id.
        $this->getJson('/api/v1/admin/audit-logs?q=77')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Combined filters narrow rather than widen.
        $this->getJson("/api/v1/admin/audit-logs?tenant={$one->uuid}&action=tenant.updated")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_audit_log_exports_the_filtered_set_as_csv(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $one = $this->tenant('ahmed', 'Ahmed Academy');
        $two = $this->tenant('mona', 'Mona Academy');
        $this->log(['tenant_id' => $one->id]);
        $this->log(['tenant_id' => $two->id, 'action' => 'tenant.updated']);

        $response = $this->get("/api/v1/admin/audit-logs/export?tenant={$one->uuid}");
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Ahmed Academy', $csv);
        $this->assertStringNotContainsString('Mona Academy', $csv);
    }

    public function test_audit_actions_lists_only_what_the_log_holds(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->log([]);

        $this->getJson('/api/v1/admin/audit-logs/actions')
            ->assertOk()
            ->assertJsonPath('data.0', 'order.refunded')
            ->assertJsonCount(1, 'data');
    }

    public function test_tenant_list_searches_name_slug_owner_and_domain(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $one = $this->tenant('ahmed', 'Ahmed Academy');
        $one->domains()->create(['host' => 'ahmed-math.com', 'type' => 'custom', 'is_primary' => true]);
        $owner = User::factory()->create(['name' => 'Ahmed Tammam']);
        TenantUser::create([
            'tenant_id' => $one->id, 'user_id' => $owner->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);
        $one->forceFill(['owner_user_id' => $owner->id])->save();

        $this->tenant('mona', 'Mona Academy');

        foreach (['Ahmed Academy', 'ahmed', 'Tammam', 'ahmed-math.com'] as $term) {
            $this->getJson('/api/v1/admin/tenants?q='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.slug', 'ahmed');
        }

        // A term that matches nothing returns an empty set, not everything.
        $this->getJson('/api/v1/admin/tenants?q=zzz')->assertOk()->assertJsonCount(0, 'data');
    }
}
