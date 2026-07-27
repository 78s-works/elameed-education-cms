<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedeemCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['phone' => '01000000001']);
        $this->tenant = Tenant::create([
            'slug' => 'redeem-test',
            'name' => 'Redeem Test',
            'status' => 'active',
            'owner_user_id' => $owner->id,
            'dedicated_db_connection' => 'shared',
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->student = User::factory()->create(['phone' => '01000000002']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->student->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);
    }

    private function makeCode(CodeStatus $status): ActivationCode
    {
        $code = new ActivationCode([
            'code' => strtoupper(str()->random(12)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 5000,
            'status' => $status->value,
        ]);
        $code->tenant_id = $this->tenant->id;
        $code->save();

        return $code;
    }

    public function test_redeeming_a_disabled_code_returns_422_not_500(): void
    {
        $code = $this->makeCode(CodeStatus::Disabled);

        Sanctum::actingAs($this->student);
        $res = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/v1/codes/redeem', ['code' => $code->code]);

        $res->assertStatus(422);
    }

    public function test_redeeming_an_invalid_code_returns_422(): void
    {
        Sanctum::actingAs($this->student);
        $res = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/v1/codes/redeem', ['code' => 'NOSUCHCODE99']);

        $res->assertStatus(422);
    }

    public function test_redeeming_an_active_wallet_code_credits_and_succeeds(): void
    {
        $code = $this->makeCode(CodeStatus::Active);

        Sanctum::actingAs($this->student);
        $res = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/v1/codes/redeem', ['code' => $code->code]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('activation_codes', ['id' => $code->id, 'status' => 'redeemed']);
    }
}
