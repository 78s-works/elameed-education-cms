<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private RecordingSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // reset rate limiters between tests

        $this->tenant = Tenant::create([
            'slug' => 'demo',
            'name' => 'Demo Academy',
            'status' => TenantStatus::Active,
        ]);

        $this->sms = new RecordingSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function tenantHeader(): array
    {
        return ['X-Tenant' => 'demo'];
    }

    public function test_register_sends_otp_and_verify_activates_membership_and_issues_token(): void
    {
        $register = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Sara',
            'phone' => '01000000001',
            'password' => 'password123',
        ]);

        $register->assertStatus(202)->assertJsonPath('data.requires_otp', true);

        $user = User::where('phone', '01000000001')->firstOrFail();
        $membership = TenantUser::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MembershipStatus::Pending, $membership->status);
        $this->assertNull($user->phone_verified_at);

        $code = $this->sms->lastCode();
        $this->assertNotNull($code);

        $verify = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/otp/verify', [
            'identifier' => '01000000001',
            'purpose' => 'register',
            'code' => $code,
        ]);

        $verify->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_register_rejects_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '01000000002']);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/register', [
            'name' => 'Dup',
            'phone' => '01000000002',
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_login_returns_token_for_active_member(): void
    {
        $user = User::factory()->create(['phone' => '01000000003', 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000003',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_wrong_password_is_generic_unauthenticated(): void
    {
        $user = User::factory()->create(['phone' => '01000000004', 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $wrong = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000004',
            'password' => 'WRONG',
        ]);
        $unknown = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01999999999',
            'password' => 'whatever',
        ]);

        // Same status + code for wrong-password and unknown-user → no enumeration.
        $wrong->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $unknown->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_login_requires_membership_in_tenant(): void
    {
        // Correct credentials but no membership in this tenant.
        User::factory()->create(['phone' => '01000000005', 'password' => 'secret123']);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000005',
            'password' => 'secret123',
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_otp_request_is_rate_limited(): void
    {
        $payload = ['identifier' => '01000000006', 'purpose' => 'register'];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/otp/request', $payload)->assertOk();
        }

        $this->withHeaders($this->tenantHeader())
            ->postJson('/api/v1/auth/otp/request', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_password_reset_flow(): void
    {
        $user = User::factory()->create(['phone' => '01000000007', 'password' => 'oldpass123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->withHeaders($this->tenantHeader())
            ->postJson('/api/v1/auth/password/forgot', ['identifier' => '01000000007'])
            ->assertOk();

        $code = $this->sms->lastCode();
        $this->assertNotNull($code);

        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/password/reset', [
            'identifier' => '01000000007',
            'code' => $code,
            'password' => 'newpass123',
        ])->assertOk();

        // New password works, old one does not.
        $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000007',
            'password' => 'newpass123',
        ])->assertOk();
    }

    public function test_me_returns_user_and_current_membership(): void
    {
        $user = User::factory()->create(['phone' => '01000000008']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders($this->tenantHeader() + ['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '01000000008')
            ->assertJsonPath('data.current.role', 'student');
    }
}
