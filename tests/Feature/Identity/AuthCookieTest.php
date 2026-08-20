<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The httpOnly-cookie auth bridge (XSS-hardening migration): login sets the token
 * in an httpOnly cookie, a request carrying only that cookie authenticates via
 * AuthenticateWithTokenCookie, cookie-authed mutations require the double-submit
 * CSRF token, and logout clears the cookie. Bearer-header auth is unaffected.
 */
class AuthCookieTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = Tenant::create([
            'slug' => 'demo',
            'name' => 'Demo Academy',
            'status' => TenantStatus::Active,
        ]);
    }

    private function tenantHeader(): array
    {
        return ['X-Tenant' => 'demo'];
    }

    private function student(string $phone = '01000000010'): User
    {
        $user = User::factory()->create(['phone' => $phone, 'password' => 'secret123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function cookieName(): string
    {
        return (string) config('authcookie.name');
    }

    private function csrfName(): string
    {
        return (string) config('authcookie.csrf_name');
    }

    public function test_login_sets_httponly_token_cookie_and_readable_csrf_cookie(): void
    {
        $this->student();

        $res = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000010',
            'password' => 'secret123',
        ])->assertOk();

        // The body still carries the token (Bearer clients); the cookie is the
        // browser transport.
        $res->assertJsonStructure(['data' => ['token', 'user']]);

        $tokenCookie = $res->getCookie($this->cookieName(), false);
        $this->assertNotNull($tokenCookie, 'token cookie is set');
        $this->assertTrue($tokenCookie->isHttpOnly(), 'token cookie is httpOnly');
        $this->assertSame($res->json('data.token'), $tokenCookie->getValue());

        $csrf = $res->getCookie($this->csrfName(), false);
        $this->assertNotNull($csrf, 'csrf cookie is set');
        $this->assertFalse($csrf->isHttpOnly(), 'csrf cookie is readable by JS');
    }

    public function test_cookie_alone_authenticates_a_request(): void
    {
        $this->student();

        $login = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000010',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->getCookie($this->cookieName(), false)->getValue();

        // No Authorization header — only the cookie. /me must authenticate.
        $this->withCredentials()->withHeaders($this->tenantHeader())
            ->withUnencryptedCookie($this->cookieName(), $token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '01000000010');
    }

    public function test_cookie_authed_mutation_requires_csrf_token(): void
    {
        $this->student();

        $login = $this->withHeaders($this->tenantHeader())->postJson('/api/v1/auth/login', [
            'identifier' => '01000000010',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->getCookie($this->cookieName(), false)->getValue();

        // Cookie-authed POST with NO CSRF header → rejected.
        $this->withCredentials()->withHeaders($this->tenantHeader())
            ->withUnencryptedCookie($this->cookieName(), $token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(419);

        // Same request WITH matching double-submit token → allowed.
        $this->withCredentials()->withHeaders($this->tenantHeader() + ['X-CSRF-Token' => 'csrf-value'])
            ->withUnencryptedCookie($this->cookieName(), $token)
            ->withUnencryptedCookie($this->csrfName(), 'csrf-value')
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    public function test_bearer_clients_are_not_subject_to_cookie_csrf(): void
    {
        $user = $this->student();
        $token = $user->createToken('api')->plainTextToken;

        // Bearer header, no cookie → CSRF guard does not apply.
        $this->withHeaders($this->tenantHeader() + ['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    public function test_logout_clears_the_token_cookie(): void
    {
        $user = $this->student();
        $token = $user->createToken('api')->plainTextToken;

        $res = $this->withHeaders($this->tenantHeader() + ['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $cleared = $res->getCookie($this->cookieName(), false);
        $this->assertNotNull($cleared, 'logout emits a forget cookie');
        // A forget cookie has an empty value and a past expiry.
        $this->assertSame('', (string) $cleared->getValue());
    }
}
