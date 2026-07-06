<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\UserLookup;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Verifies an OTP for `register` or `login` and issues an access token.
 * For `register` it also marks the phone verified and activates the pending
 * membership in the current tenant. (Password reset has its own action.)
 *
 * @return array{token: string, user: User}
 */
class VerifyOtpAction
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly NotificationService $notifications,
    ) {}

    public function handle(string $identifier, OtpPurpose $purpose, string $code, ?Tenant $tenant): array
    {
        if (! $this->otp->verify($identifier, $purpose, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The code is invalid or has expired.'),
            ]);
        }

        $user = UserLookup::find($identifier);

        if ($user === null) {
            throw ValidationException::withMessages([
                'identifier' => __('These credentials do not match our records.'),
            ]);
        }

        if ($purpose === OtpPurpose::Register) {
            $this->activateRegistration($user, $tenant);
        }

        return [
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user->fresh(),
        ];
    }

    private function activateRegistration(User $user, ?Tenant $tenant): void
    {
        if ($user->phone_verified_at === null) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        if ($tenant === null) {
            return;
        }

        $membership = $user->membershipFor($tenant);

        if ($membership !== null && ! $membership->isActive()) {
            $membership->update([
                'status' => MembershipStatus::Active->value,
                'joined_at' => $membership->joined_at ?? now(),
            ]);
        }

        $this->notifications->inApp($tenant->getKey(), $user->getKey(), 'account.welcome', [
            'name' => $user->name,
        ]);
    }
}
