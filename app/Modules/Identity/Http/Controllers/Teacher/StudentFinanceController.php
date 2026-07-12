<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Commerce\Http\Resources\OrderResource;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\WalletAdjustRequest;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Http\Resources\LedgerEntryResource;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Teacher's full control of a student's money. Every change is posted to the
 * double-entry ledger as a balanced `adjustment` against teacher_earnings —
 * never a raw balance edit — and is audit-logged. The teacher can view the
 * balance + full history, credit/debit, and set the wallet to an exact amount.
 */
class StudentFinanceController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** Balance + the most recent entries. */
    public function wallet(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $wallet = $this->ledger->walletFor($tenantId, $student->getKey());
        $recent = $wallet->entries()->latest('id')->limit(15)->get();

        return response()->json(['data' => [
            'balance_minor' => $this->ledger->balance($wallet),
            'currency' => $wallet->currency,
            'recent' => LedgerEntryResource::collection($recent)->resolve(request()),
        ]]);
    }

    /** Full, paginated wallet history. */
    public function ledger(User $student): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $entries = $this->ledger->walletFor($tenantId, $student->getKey())
            ->entries()->latest('id')->paginate(30);

        return LedgerEntryResource::collection($entries);
    }

    /** Credit or debit by an amount. */
    public function adjust(WalletAdjustRequest $request, User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $amount = (int) $request->validated('amount_minor');
        $isCredit = $request->validated('direction') === 'credit';
        $wallet = $this->ledger->walletFor($tenantId, $student->getKey());

        if (! $isCredit && $this->ledger->balance($wallet) < $amount) {
            throw ValidationException::withMessages(['amount_minor' => __('Balance is too low for this deduction.')]);
        }

        $this->postAdjustment($tenantId, $wallet, $student, $isCredit ? $amount : -$amount, $request->validated('reason'), 'wallet.adjust');

        return response()->json(['data' => ['balance_minor' => $this->ledger->balance($wallet->fresh())]]);
    }

    /** Set the wallet to an exact balance (posts the difference as an adjustment). */
    public function setBalance(Request $request, User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $data = $request->validate([
            'balance_minor' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $wallet = $this->ledger->walletFor($tenantId, $student->getKey());
        $delta = (int) $data['balance_minor'] - $this->ledger->balance($wallet);

        if ($delta !== 0) {
            $this->postAdjustment($tenantId, $wallet, $student, $delta, $data['reason'] ?? null, 'wallet.set');
        }

        return response()->json(['data' => ['balance_minor' => $this->ledger->balance($wallet->fresh())]]);
    }

    public function orders(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->with('items')
            ->latest('id')
            ->get();

        return response()->json(['data' => OrderResource::collection($orders)->resolve(request())]);
    }

    /**
     * Post a balanced wallet adjustment. `$signedDelta > 0` credits the student
     * (funded by teacher_earnings); `< 0` debits back to teacher_earnings.
     */
    private function postAdjustment(int $tenantId, Wallet $wallet, User $student, int $signedDelta, ?string $reason, string $action): void
    {
        $amount = abs($signedDelta);
        $isCredit = $signedDelta > 0;

        $legs = $isCredit
            ? [
                $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::CREDIT, $amount, $wallet->id),
                $this->leg(LedgerEntry::TEACHER_EARNINGS, LedgerEntry::DEBIT, $amount),
            ]
            : [
                $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::DEBIT, $amount, $wallet->id),
                $this->leg(LedgerEntry::TEACHER_EARNINGS, LedgerEntry::CREDIT, $amount),
            ];

        $this->ledger->post($tenantId, $action.':'.$tenantId.':'.$student->getKey().':'.Str::uuid(), $legs, 'adjustment', $student->getKey());

        $this->audit->log($action, [
            'student_id' => $student->getKey(),
            'amount_minor' => $amount,
            'direction' => $isCredit ? 'credit' : 'debit',
            'reason' => $reason,
        ], $tenantId, 'user', $student->getKey());
    }

    private function leg(string $account, string $direction, int $amount, ?int $walletId = null): array
    {
        return ['account' => $account, 'direction' => $direction, 'amount_minor' => $amount, 'wallet_id' => $walletId];
    }
}
