<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Centers\Enums\CodeType;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B22 — payment scratch codes are `activation_codes` of type=wallet: denominated,
 * batched, single-use, printable. Covers the batch generator (denomination + quantity
 * + expiry), code uniqueness/format/provenance, list buckets, and exact-denomination
 * wallet credit on redeem.
 */
class ScratchCodeBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'scratch', 'name' => 'Scratch', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'scratch'];
    }

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    public function test_batch_generates_quantity_of_denominated_expiring_codes(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);
        $expiry = now()->addMonth()->toIso8601String();

        $codes = $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'wallet', 'count' => 5, 'amount_minor' => 7500, 'batch' => 'CARDS-A', 'expires_at' => $expiry,
        ])->assertStatus(201)->json('data');

        $this->assertCount(5, $codes);
        foreach ($codes as $c) {
            $this->assertSame('wallet', $c['type']);
            $this->assertSame(7500, $c['amount_minor']);      // denomination
            $this->assertSame('active', $c['status']);
            $this->assertSame($teacher->id, $c['generated_by']); // provenance
            $this->assertNotNull($c['expires_at']);
            // printable format: XXXX-XXXX-XXXX, no ambiguous 0/O/1/I
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $c['code']);
        }
    }

    public function test_generated_codes_are_unique(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $codes = $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'wallet', 'count' => 50, 'amount_minor' => 1000,
        ])->assertStatus(201)->json('data');

        $values = array_column($codes, 'code');
        $this->assertCount(50, array_unique($values));
    }

    public function test_redeeming_credits_exactly_the_denomination(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $code = $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'wallet', 'count' => 1, 'amount_minor' => 12500,
        ])->assertStatus(201)->json('data.0.code');

        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($student);

        $this->withHeaders($this->h)->postJson('/api/v1/codes/redeem', ['code' => $code])
            ->assertOk()->assertJsonPath('data.amount_minor', 12500);

        $this->withHeaders($this->h)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance_minor', 12500);
    }

    public function test_list_filters_unused_used_and_expired_buckets(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);

        // unused: active, future expiry
        $unused = $this->code($teacher, ['status' => 'active', 'expires_at' => now()->addWeek()]);
        // expired: active but past expiry (derived state, not a stored status)
        $expired = $this->code($teacher, ['status' => 'active', 'expires_at' => now()->subDay()]);
        // used: redeemed
        $used = $this->code($teacher, ['status' => 'redeemed', 'expires_at' => now()->addWeek()]);

        Sanctum::actingAs($teacher);

        $this->assertOnlyCode('unused', $unused);
        $this->assertOnlyCode('expired', $expired);
        $this->assertOnlyCode('used', $used);
    }

    private function assertOnlyCode(string $bucket, ActivationCode $expected): void
    {
        $data = $this->withHeaders($this->h)
            ->getJson('/api/v1/teacher/codes?filter[status]='.$bucket)
            ->assertOk()->json('data');

        $this->assertCount(1, $data, "bucket {$bucket} should return exactly one code");
        $this->assertSame($expected->uuid, $data[0]['uuid']);
    }

    private function code(User $generatedBy, array $attrs): ActivationCode
    {
        $c = new ActivationCode(array_merge([
            'code' => strtoupper(str()->random(12)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 5000,
            'generated_by' => $generatedBy->id,
        ], $attrs));
        $c->tenant_id = $this->tenant->id;
        $c->save();

        return $c;
    }
}
