<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Models\RoleTemplate;
use App\Modules\Identity\Services\TenantRoleProvisioner;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two commercial surfaces: what an academy has paid us (ADM-16), and the
 * preview that has to stand between an admin and a push that overwrites what
 * teachers configured (ADM-18).
 */
class AdminBillingAndPushTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'ahmed', string $name = 'Ahmed Academy'): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $name, 'status' => TenantStatus::Active]);
    }

    public function test_billing_lists_charges_and_reconciles_with_the_plan(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $tenant = $this->tenant();

        $package = SubscriptionPackage::create([
            'slug' => 'growth', 'name' => 'Growth', 'price_minor' => 149900,
            'interval' => 'monthly', 'trial_days' => 0, 'limits' => [],
        ]);
        // Assigned below list price, with the reason recorded at that moment.
        app(SubscriptionService::class)->assign(
            $tenant, $package, 99900, null, ['discount_reason' => 'early adopter'],
        );

        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/billing", [
            'amount_minor' => 99900,
            'method' => 'instapay',
            'reference' => 'IPN-1',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/billing", [
            'amount_minor' => 99900,
            'status' => 'failed',
            'method' => 'card',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/tenants/{$tenant->uuid}/billing")
            ->assertOk()
            ->assertJsonCount(2, 'data.charges')
            // Only settled money counts towards what they have actually paid.
            ->assertJsonPath('data.summary.total_paid_minor', 99900)
            ->assertJsonPath('data.summary.charged_price_minor', 99900)
            ->assertJsonPath('data.summary.plan_price_minor', 149900)
            ->assertJsonPath('data.summary.discount_reason', 'early adopter')
            ->assertJsonPath('data.summary.subscription_status', 'active')
            ->assertJsonPath('data.summary.has_failed', true);
    }

    public function test_recording_a_charge_is_audited_and_admin_only(): void
    {
        $tenant = $this->tenant();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/billing", ['amount_minor' => 100])
            ->assertStatus(403);

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->postJson("/api/v1/admin/tenants/{$tenant->uuid}/billing", ['amount_minor' => 100])
            ->assertCreated();

        $this->assertTrue(
            AuditLog::query()->where('action', 'tenant.subscription.charge_recorded')->exists(),
        );
    }

    public function test_push_preview_names_academies_and_flags_the_customised_ones(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $one = $this->tenant('ahmed', 'Ahmed Academy');
        $two = $this->tenant('mona', 'Mona Academy');
        $provisioner = app(TenantRoleProvisioner::class);
        $provisioner->provision($one);
        $provisioner->provision($two);

        $template = RoleTemplate::where('key', 'finance')->with('permissions')->firstOrFail();

        // One academy tunes its copy; that is the one at risk of losing changes.
        $role = Role::query()->where('tenant_id', $one->id)->where('template_key', 'finance')->firstOrFail();
        $extra = Permission::query()->where('name', PermissionEnum::values()[0])->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$extra->id]);

        $response = $this->getJson("/api/v1/admin/role-templates/{$template->id}/resync-preview")
            ->assertOk()
            ->assertJsonPath('data.overwrites_local_changes', true)
            ->assertJsonPath('data.total', 2);

        $academies = collect($response->json('data.academies'));
        $customised = $academies->firstWhere('customised', true);

        $this->assertSame('Ahmed Academy', $customised['tenant_name']);
        $this->assertSame(1, $response->json('data.customised_total'));
        // The preview itself changes nothing.
        $this->assertTrue($role->fresh()->permissions->contains('id', $extra->id));
    }

    public function test_push_can_be_scoped_and_writes_one_audit_entry_per_academy(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $one = $this->tenant('ahmed', 'Ahmed Academy');
        $two = $this->tenant('mona', 'Mona Academy');
        $provisioner = app(TenantRoleProvisioner::class);
        $provisioner->provision($one);
        $provisioner->provision($two);

        $template = RoleTemplate::where('key', 'finance')->with('permissions')->firstOrFail();

        $this->postJson("/api/v1/admin/role-templates/{$template->id}/resync", [
            'tenants' => [$one->uuid],
        ])
            ->assertOk()
            ->assertJsonPath('data.roles_updated', 1)
            ->assertJsonPath('data.academies.0', 'Ahmed Academy');

        $entries = AuditLog::query()->where('action', 'role_template.pushed')->get();
        $this->assertCount(1, $entries);
        $this->assertSame($one->id, $entries->first()->tenant_id);
    }
}
