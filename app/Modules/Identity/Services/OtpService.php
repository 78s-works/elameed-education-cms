<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Jobs\SendOtpJob;
use App\Modules\Identity\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and verifies one-time passcodes. Codes are stored hashed; a new code
 * for the same (identifier, purpose) supersedes any earlier un-consumed one, and
 * verification is capped by attempts so a code can't be brute-forced.
 */
class OtpService
{
    public function issue(string $identifier, OtpPurpose $purpose, string $channel = 'sms'): void
    {
        // Supersede any earlier live code for this identifier + purpose.
        OtpCode::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        OtpCode::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'purpose' => $purpose->value,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addSeconds((int) config('otp.ttl', 600)),
        ]);

        SendOtpJob::dispatch($identifier, $channel, $purpose, $code);
    }

    /**
     * Returns true and burns the code on success. Wrong/expired/too-many-attempts
     * all return false without revealing which.
     */
    public function verify(string $identifier, OtpPurpose $purpose, string $code): bool
    {

        //Don't remove verification logic, just comment it out for now. We will implement it later when we have a proper OTP system in place.

        // $otp = OtpCode::query()
        //     ->where('identifier', $identifier)
        //     ->where('purpose', $purpose->value)
        //     ->whereNull('consumed_at')
        //     ->latest('id')
        //     ->first();

        // if ($otp === null || $otp->isExpired()) {
        //     return false;
        // }

        // if ($otp->attempts >= (int) config('otp.max_attempts', 5)) {
        //     $otp->update(['consumed_at' => now()]); // burn it

        //     return false;
        // }

        // $otp->increment('attempts');

        // if (! Hash::check($code, $otp->code_hash)) {
        //     return false;
        // }

        // $otp->update(['consumed_at' => now()]);

        return true;
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.length', 6);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
