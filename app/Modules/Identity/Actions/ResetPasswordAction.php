<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\UserLookup;
use Illuminate\Validation\ValidationException;

/**
 * Completes an OTP-based password reset (FR-M11-02): verify the reset code for
 * the identifier, set the new password, and revoke existing tokens so any
 * session opened before the reset is invalidated.
 */
class ResetPasswordAction
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(string $identifier, string $code, string $password): void
    {
        if (! $this->otp->verify($identifier, OtpPurpose::Reset, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The code is invalid or has expired.'),
            ]);
        }

        $user = UserLookup::find($identifier);

        if ($user === null) {
            // Should not happen (a code only issues for an existing user), but
            // stay generic rather than confirm/deny the identifier.
            throw ValidationException::withMessages([
                'code' => __('The code is invalid or has expired.'),
            ]);
        }

        $user->forceFill(['password' => $password])->save(); // hashed by cast
        $user->tokens()->delete();
    }
}
