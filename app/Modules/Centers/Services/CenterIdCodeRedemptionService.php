<?php

namespace App\Modules\Centers\Services;

use App\Models\User;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Models\CenterIdCode;
use Illuminate\Validation\ValidationException;

/**
 * Consumes a Center ID-code (B20) at student registration (B21): validates the
 * code is known + unused, then flips it to `redeemed` and stamps `used_by`/
 * `used_at`. Returns the code so the caller (RegisterStudentAction) can bind
 * `center_id` + grade + study_mode onto the new student's profile.
 *
 * NO own transaction / lock outside one: the register action already runs inside
 * a DB::transaction, and this locks the row (`lockForUpdate`) so a double-submit
 * can never bind the same code to two students. Center ID-codes carry no expiry
 * column (unlike activation_codes) — "unused" (status = active) is the only gate.
 */
class CenterIdCodeRedemptionService
{
    public function consume(int $tenantId, string $code, User $student): CenterIdCode
    {
        $idCode = CenterIdCode::withoutGlobalScopes()
            ->where('tenant_id', $tenantId) // belongs to a center in THIS tenant
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if ($idCode === null) {
            throw ValidationException::withMessages(['id_code' => __('Invalid ID code.')]);
        }

        if (! $idCode->isUnused()) {
            $msg = $idCode->status === CodeStatus::Disabled
                ? __('This ID code has been disabled.')
                : __('This ID code has already been used.');
            throw ValidationException::withMessages(['id_code' => $msg]);
        }

        $idCode->update([
            'status' => CodeStatus::Redeemed->value,
            'used_by' => $student->getKey(),
            'used_at' => now(),
        ]);

        return $idCode;
    }
}
