<?php

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Engagement\Models\Attachment;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Http\Requests\SubmitManualTopupRequest;
use App\Modules\Wallet\Http\Resources\LedgerEntryResource;
use App\Modules\Wallet\Http\Resources\PaymentReceiptResource;
use App\Modules\Wallet\Services\LedgerService;
use App\Modules\Wallet\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /wallet (M06) — the current student's wallet in the current tenant. Balance is
 * derived from the ledger.
 */
class WalletController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LedgerService $ledger,
        private readonly PaymentReceiptService $receipts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->ledger->walletFor($this->context->tenantOrFail()->getKey(), $request->user()->getKey());

        $recent = $wallet->entries()->latest('id')->limit(10)->get();

        return response()->json([
            'data' => [
                'balance_minor' => $this->ledger->balance($wallet),
                'currency' => $wallet->currency,
                'recent' => LedgerEntryResource::collection($recent)->resolve($request),
            ],
        ]);
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        $wallet = $this->ledger->walletFor($this->context->tenantOrFail()->getKey(), $request->user()->getKey());

        return LedgerEntryResource::collection(
            $wallet->entries()->latest('id')->paginate(30)
        );
    }

    /**
     * Submit a manual top-up (VD R9): a Vodafone Cash / InstaPay receipt image →
     * `pending`. The wallet is not credited until a teacher/assistant approves it.
     */
    public function topupManual(SubmitManualTopupRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        // Ownership + tenant already enforced by the request's exists rule.
        $attachment = Attachment::where('uuid', $request->validated('attachment_id'))->firstOrFail();

        $receipt = $this->receipts->submit(
            $tenantId,
            $request->user()->getKey(),
            $request->validated('method'),
            (int) $request->validated('amount_minor'),
            $attachment->getKey(),
        );

        return (new PaymentReceiptResource($receipt))->response()->setStatusCode(201);
    }
}
