<?php

namespace App\Modules\Wallet\Services;

use App\Models\User;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Manual wallet top-ups (VD R9/R10). Submit creates a `pending` receipt; approve
 * credits the student's wallet through the double-entry ledger — once, idempotently
 * — and reject stamps a reason. Reviewer actions run in a locked transaction so a
 * concurrent double-tap can neither double-review nor double-credit.
 */
class PaymentReceiptService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Student submits a receipt → a `pending` row. */
    public function submit(int $tenantId, int $userId, string $method, int $amountMinor, int $attachmentId, string $currency = 'EGP'): PaymentReceipt
    {
        $receipt = new PaymentReceipt([
            'user_id' => $userId,
            'method' => $method,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'attachment_id' => $attachmentId,
            'status' => PaymentReceipt::STATUS_PENDING,
        ]);
        $receipt->tenant_id = $tenantId;
        $receipt->save();

        return $receipt;
    }

    /**
     * Approve a pending receipt: post a `student_wallet` credit funded by
     * `gateway_clearing` (external cash in), then stamp the review. Guards
     * `status === pending` (else 409). The ledger op key `receipt:{id}` is UNIQUE,
     * so even if the guard were bypassed the credit can never post twice.
     *
     * `$correctedAmountMinor` (VD F4 / D13-7) lets the reviewer credit a value that
     * differs from the student-submitted `amount_minor` — e.g. the student typed the
     * wrong figure. When given it must be > 0 and within a sane ceiling; the corrected
     * value funds BOTH ledger legs and is stamped on `corrected_amount_minor`, while
     * `amount_minor` stays the original submitted figure (the audit baseline). When
     * omitted, the submitted amount is credited and `corrected_amount_minor` stays NULL.
     */
    public function approve(PaymentReceipt $receipt, User $reviewer, ?int $correctedAmountMinor = null): PaymentReceipt
    {
        if ($correctedAmountMinor !== null && ($correctedAmountMinor <= 0 || $correctedAmountMinor > PaymentReceipt::MAX_AMOUNT_MINOR)) {
            throw new InvalidArgumentException('Corrected amount must be greater than 0 and within the allowed ceiling.');
        }

        return DB::transaction(function () use ($receipt, $reviewer, $correctedAmountMinor): PaymentReceipt {
            $fresh = PaymentReceipt::withoutGlobalScopes()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardPending($fresh);

            $creditMinor = $correctedAmountMinor ?? (int) $fresh->amount_minor;

            $tenantId = (int) $fresh->tenant_id;
            $wallet = $this->ledger->walletFor($tenantId, (int) $fresh->user_id);

            $this->ledger->post($tenantId, "receipt:{$fresh->id}", [
                ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $creditMinor, 'wallet_id' => $wallet->id],
                ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $creditMinor],
            ], 'receipt', (int) $fresh->id);

            $ledgerEntryId = LedgerEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('ref_type', 'receipt')
                ->where('ref_id', $fresh->id)
                ->where('account', LedgerEntry::STUDENT_WALLET)
                ->where('direction', LedgerEntry::CREDIT)
                ->value('id');

            $fresh->forceFill([
                'status' => PaymentReceipt::STATUS_APPROVED,
                'corrected_amount_minor' => $correctedAmountMinor,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'ledger_entry_id' => $ledgerEntryId,
            ])->save();

            return $fresh;
        });
    }

    /** Reject a pending receipt with a reason. No ledger effect. */
    public function reject(PaymentReceipt $receipt, User $reviewer, string $reason): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $reviewer, $reason): PaymentReceipt {
            $fresh = PaymentReceipt::withoutGlobalScopes()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardPending($fresh);

            $fresh->forceFill([
                'status' => PaymentReceipt::STATUS_REJECTED,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'reject_reason' => $reason,
            ])->save();

            return $fresh;
        });
    }

    private function guardPending(PaymentReceipt $receipt): void
    {
        if ($receipt->status !== PaymentReceipt::STATUS_PENDING) {
            throw new ConflictHttpException('This receipt has already been reviewed.');
        }
    }
}
