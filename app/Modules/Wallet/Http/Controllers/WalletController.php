<?php

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Http\Resources\LedgerEntryResource;
use App\Modules\Wallet\Services\LedgerService;
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
}
