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
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Teacher view + control of a student's money. Wallet changes are posted to the
 * double-entry ledger as balanced `adjustment` entries against teacher_earnings
 * — never a raw balance edit. (Audit-log of who/why is a documented follow-up.)
 */
class StudentFinanceController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

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

        // Balanced against teacher_earnings: a credit is funded by the teacher; a
        // deduction returns to the teacher.
        $legs = $isCredit
            ? [
                $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::CREDIT, $amount, $wallet->id),
                $this->leg(LedgerEntry::TEACHER_EARNINGS, LedgerEntry::DEBIT, $amount),
            ]
            : [
                $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::DEBIT, $amount, $wallet->id),
                $this->leg(LedgerEntry::TEACHER_EARNINGS, LedgerEntry::CREDIT, $amount),
            ];

        $this->ledger->post($tenantId, 'adjust:'.$tenantId.':'.$student->getKey().':'.Str::uuid(), $legs, 'adjustment', $student->getKey());

        $this->audit->log('wallet.adjust', [
            'student_id' => $student->getKey(),
            'amount_minor' => $amount,
            'direction' => $isCredit ? 'credit' : 'debit',
            'reason' => $request->validated('reason'),
        ], $tenantId, 'user', $student->getKey());

        return response()->json(['data' => [
            'balance_minor' => $this->ledger->balance($wallet->fresh()),
        ]]);
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

    private function leg(string $account, string $direction, int $amount, ?int $walletId = null): array
    {
        return ['account' => $account, 'direction' => $direction, 'amount_minor' => $amount, 'wallet_id' => $walletId];
    }
}
