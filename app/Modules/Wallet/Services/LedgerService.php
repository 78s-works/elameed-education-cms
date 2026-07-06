<?php

namespace App\Modules\Wallet\Services;

use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place money is written. Every post is a set of balanced legs
 * (Σdebits == Σcredits) inserted append-only. Idempotent on a caller-supplied
 * operation key: a replayed webhook/operation posts nothing new.
 *
 * All methods take an explicit tenant id — ledger writes happen in webhook
 * contexts where no tenant is resolved from the host, so we never rely on the
 * request-scoped tenant here.
 */
class LedgerService
{
    public function walletFor(int $tenantId, int $userId): Wallet
    {
        $wallet = Wallet::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        $wallet = new Wallet(['user_id' => $userId]);
        $wallet->tenant_id = $tenantId;
        $wallet->save();

        return $wallet;
    }

    /** Derived student-wallet balance (credits − debits), in minor units. */
    public function balance(Wallet $wallet): int
    {
        $sum = fn (string $direction) => (int) LedgerEntry::withoutGlobalScopes()
            ->where('wallet_id', $wallet->id)
            ->where('account', LedgerEntry::STUDENT_WALLET)
            ->where('direction', $direction)
            ->sum('amount_minor');

        return $sum(LedgerEntry::CREDIT) - $sum(LedgerEntry::DEBIT);
    }

    /** True if the operation identified by $opKey has already been posted. */
    public function alreadyPosted(string $opKey): bool
    {
        return LedgerEntry::withoutGlobalScopes()
            ->where('idempotency_key', 'like', $this->escapeLike($opKey).':%')
            ->exists();
    }

    /**
     * @param  array<int, array{account: string, direction: string, amount_minor: int, wallet_id?: int|null}>  $legs
     */
    public function post(int $tenantId, string $opKey, array $legs, ?string $refType = null, ?int $refId = null): void
    {
        if ($this->alreadyPosted($opKey)) {
            return; // idempotent no-op
        }

        $debits = $this->sumByDirection($legs, LedgerEntry::DEBIT);
        $credits = $this->sumByDirection($legs, LedgerEntry::CREDIT);

        if ($debits !== $credits) {
            throw new RuntimeException("Unbalanced ledger post ({$opKey}): debits {$debits} != credits {$credits}.");
        }

        if ($debits === 0) {
            return; // nothing to record (e.g. a free enrollment)
        }

        try {
            DB::transaction(function () use ($tenantId, $opKey, $legs, $refType, $refId): void {
                foreach ($legs as $i => $leg) {
                    $entry = new LedgerEntry([
                        'wallet_id' => $leg['wallet_id'] ?? null,
                        'account' => $leg['account'],
                        'direction' => $leg['direction'],
                        'amount_minor' => $leg['amount_minor'],
                        'ref_type' => $refType,
                        'ref_id' => $refId,
                        'idempotency_key' => $opKey.':'.$i.':'.$leg['account'].':'.$leg['direction'],
                    ]);
                    $entry->tenant_id = $tenantId;
                    $entry->created_at = now();
                    $entry->save();
                }
            });
        } catch (QueryException $e) {
            // Unique idempotency_key violation → a concurrent replay won; treat as done.
            if (($e->errorInfo[0] ?? null) === '23000') {
                return;
            }
            throw $e;
        }
    }

    /** @param array<int, array{direction: string, amount_minor: int}> $legs */
    private function sumByDirection(array $legs, string $direction): int
    {
        return array_sum(array_map(
            fn ($leg) => $leg['direction'] === $direction ? (int) $leg['amount_minor'] : 0,
            $legs,
        ));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
