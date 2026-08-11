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
use Illuminate\Support\Facades\DB;
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

    private function makeCode(CodeStatus $status, array $attrs = []): ActivationCode
    {
        $code = new ActivationCode(array_merge([
            'code' => strtoupper(str()->random(12)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 5000,
            'status' => $status->value,
        ], $attrs));
        $code->tenant_id = $this->tenant->id;
        $code->save();

        return $code;
    }

    private function redeem(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/v1/codes/redeem', ['code' => $code]);
    }

    /** The student's derived wallet balance in minor units, via the wallet endpoint. */
    private function walletBalance(): int
    {
        return (int) $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->getJson('/api/v1/wallet')->assertOk()->json('data.balance_minor');
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

    // ── B23 — scratch-code redemption invariants ──────────────────────────────

    /** Exact denomination — no change, no top-up: wallet is credited by exactly amount_minor. */
    public function test_redeeming_credits_exactly_the_code_denomination(): void
    {
        $code = $this->makeCode(CodeStatus::Active, ['amount_minor' => 7500]);

        Sanctum::actingAs($this->student);
        $this->redeem($code->code)
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 7500);

        $this->assertSame(7500, $this->walletBalance()); // exact — nothing more, nothing less
    }

    /** Expired code (active but past expires_at) is rejected with a clear error and never credits. */
    public function test_expired_code_is_rejected_and_not_credited(): void
    {
        $code = $this->makeCode(CodeStatus::Active, ['expires_at' => now()->subDay()]);

        Sanctum::actingAs($this->student);
        $this->redeem($code->code)
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'This code has expired.');

        $this->assertSame(0, $this->walletBalance());
        $this->assertDatabaseHas('activation_codes', ['id' => $code->id, 'status' => 'active']); // untouched
    }

    /** Single-use: a consumed code is rejected on a second redeem and never credits twice. */
    public function test_code_is_single_use(): void
    {
        $code = $this->makeCode(CodeStatus::Active, ['amount_minor' => 5000]);

        Sanctum::actingAs($this->student);
        $this->redeem($code->code)->assertOk();
        $this->assertDatabaseHas('activation_codes', ['id' => $code->id, 'status' => 'redeemed']);

        $this->redeem($code->code)
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'This code has already been used.');

        $this->assertSame(5000, $this->walletBalance()); // credited once, not twice
    }

    /**
     * Idempotency backstop: even if the row's single-use guard is bypassed (an offline
     * re-sync / double submit that resets the status), the ledger op-key code:{uuid}
     * blocks a second credit — same pattern the payment-receipt approve path uses.
     */
    public function test_double_submit_does_not_double_credit_via_ledger_op_key(): void
    {
        $code = $this->makeCode(CodeStatus::Active, ['amount_minor' => 5000]);

        Sanctum::actingAs($this->student);
        $this->redeem($code->code)->assertOk();

        // Simulate the replay: force the row back to a redeemable state (direct DB
        // write — the service already consumed it) so the status guard passes a second
        // time. The ledger op-key code:{uuid} must still no-op the credit.
        ActivationCode::withoutGlobalScopes()->where('id', $code->id)->update([
            'status' => CodeStatus::Active->value,
            'redeemed_by' => null,
            'redeemed_at' => null,
        ]);

        $this->redeem($code->code)->assertOk();

        $this->assertSame(5000, $this->walletBalance()); // still exactly one credit
        $this->assertSame(2, DB::table('ledger_entries')
            ->where('idempotency_key', 'like', 'code:'.$code->uuid.'%')
            ->count()); // one balanced post (credit + debit); the replay recorded nothing
    }
}
