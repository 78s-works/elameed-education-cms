<?php

namespace App\Modules\Centers\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Redeems an activation code for a student (M12): wallet codes credit the wallet
 * (balanced against teacher_earnings, like a manual top-up); course codes grant a
 * course enrollment. Atomic + one-time — the code row is locked and flipped to
 * `redeemed`, so a double-submit or offline re-sync can't double-apply.
 */
class CodeRedemptionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @return array{code: string, type: string, amount_minor?: int, course_id?: int}
     */
    public function redeem(int $tenantId, string $code, User $student): array
    {
        return DB::transaction(function () use ($tenantId, $code, $student): array {
            $ac = ActivationCode::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($ac === null) {
                throw ValidationException::withMessages(['code' => __('Invalid code.')]);
            }
            if (! $ac->isRedeemable()) {
                $msg = $ac->status === CodeStatus::Active ? __('This code has expired.') : __('This code has already been used.');
                throw ValidationException::withMessages(['code' => $msg]);
            }

            if ($ac->type === CodeType::Wallet) {
                $amount = (int) $ac->amount_minor;
                $wallet = $this->ledger->walletFor($tenantId, $student->getKey());
                // opKey = code uuid → ledger-level idempotency backstop.
                $this->ledger->post($tenantId, 'code:'.$ac->uuid, [
                    ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
                    ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => null],
                ], 'activation_code', $ac->id);
                $result = ['type' => 'wallet', 'amount_minor' => $amount];
            } else {
                $course = Course::withoutGlobalScopes()->find($ac->course_id);
                if ($course === null) {
                    throw ValidationException::withMessages(['code' => __('The course for this code is no longer available.')]);
                }
                $this->enrollments->grantCourse($tenantId, $student->getKey(), $course, EnrollmentSource::Code);
                $result = ['type' => 'course', 'course_id' => (int) $ac->course_id];
            }

            $ac->update([
                'status' => CodeStatus::Redeemed->value,
                'redeemed_by' => $student->getKey(),
                'redeemed_at' => now(),
            ]);

            return ['code' => $ac->code] + $result;
        });
    }
}
