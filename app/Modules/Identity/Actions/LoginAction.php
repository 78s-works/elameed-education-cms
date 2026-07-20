<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\UserLookup;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authenticates by phone/email + password (FR-M11-01), then enforces context:
 *   - on a tenant host: the user must have an ACTIVE membership in that tenant;
 *   - on the platform host (no tenant): the user must be a platform admin.
 *
 * Every attempt is recorded (FR-M11-03). Bad credentials always yield the same
 * generic failure so a phone/email can't be enumerated (06_Engineering_Guide §7).
 * Login-OTP is optional (off by default); when on, a code is issued instead of a
 * token and the client completes via /auth/otp/verify.
 */
class LoginAction
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * @return array{otp_required: bool, token: ?string, user: ?User}
     */
    public function handle(string $identifier, string $password, ?Tenant $tenant, ?string $ip, ?string $userAgent): array
    {
        $user = UserLookup::find($identifier);
        $passwordOk = $user !== null && Hash::check($password, $user->password);

        $this->record($user?->getKey(), $tenant, $identifier, $ip, $userAgent, $passwordOk);

        if (! $passwordOk) {
            // Do not reveal whether the identifier or the password was wrong.
            throw new AuthenticationException(__('These credentials do not match our records.'));
        }

        $membership = $this->assertContextAllows($user, $tenant);
        $this->assertLoginEnabled($tenant, $membership);

        if ((bool) config('otp.login_required', false)) {
            $this->otp->issue($identifier, OtpPurpose::Login);

            return ['otp_required' => true, 'token' => null, 'user' => null];
        }

        return [
            'otp_required' => false,
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ];
    }

    /** @return TenantUser|null the active membership on a tenant host, null on the platform host */
    private function assertContextAllows(User $user, ?Tenant $tenant): ?TenantUser
    {
        if ($tenant !== null) {
            $membership = $user->membershipFor($tenant);

            if ($membership === null || ! $membership->isActive()) {
                throw new AccessDeniedHttpException(__('You are not a member of this academy.'));
            }

            return $membership;
        }

        // Platform host: only platform admins may sign in here.
        if (! $user->isPlatformAdmin()) {
            throw new AccessDeniedHttpException(__('You are not allowed to sign in here.'));
        }

        return null;
    }

    /**
     * Honour the teacher's per-academy "disable sign-in" switch. When it is off,
     * ONLY the teacher may still sign in (to reach their panel and re-open it);
     * everyone else — assistants, students, parents — is blocked. No-op on the
     * platform host.
     */
    private function assertLoginEnabled(?Tenant $tenant, ?TenantUser $membership): void
    {
        if ($tenant === null || $membership === null || $membership->role === TenantUserRole::Teacher) {
            return;
        }

        $profile = TeacherProfile::query()->first();

        if ($profile !== null && ! $profile->login_enabled) {
            throw new AccessDeniedHttpException(__('Sign-in is currently disabled for this academy.'));
        }
    }

    private function record(?int $userId, ?Tenant $tenant, string $identifier, ?string $ip, ?string $userAgent, bool $success): void
    {
        LoginAttempt::create([
            'user_id' => $userId,
            'tenant_id' => $tenant?->getKey(),
            'identifier' => $identifier,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'success' => $success,
        ]);
    }
}
