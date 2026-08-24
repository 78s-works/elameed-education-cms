<?php

namespace App\Modules\Commerce\Http\Controllers\Teacher;

use App\Modules\Commerce\Http\Requests\RefundOrderRequest;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\Refund;
use App\Modules\Commerce\Services\RefundService;
use Illuminate\Http\JsonResponse;

/**
 * Refunding a paid order, from the sales ledger. Gated on
 * `finance.refunds.manage` — a separate key from reading the ledger, because
 * this one moves money and takes back access.
 */
class RefundController
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(RefundOrderRequest $request, Order $order): JsonResponse
    {
        $refund = $this->refunds->refund(
            $order,
            $request->input('amount_minor') === null ? null : (int) $request->input('amount_minor'),
            $request->input('reason'),
            (string) $request->input('destination', Refund::TO_WALLET),
            $request->user()?->getKey(),
        );

        return response()->json(['data' => [
            'uuid' => $refund->uuid,
            'order_uuid' => $order->uuid,
            'amount_minor' => (int) $refund->amount_minor,
            'currency' => $refund->currency,
            'destination' => $refund->destination,
            'reason' => $refund->reason,
            'revoked_access' => (bool) $refund->revoked_access,
            'order_status' => $order->fresh()?->status?->value,
            'refundable_minor' => $this->refunds->refundable($order->fresh() ?? $order),
        ]], 201);
    }

    /** The refunds already posted against an order (the ledger row's detail). */
    public function index(Order $order): JsonResponse
    {
        $refunds = Refund::query()
            ->where('order_id', $order->getKey())
            ->orderByDesc('id')
            ->get(['uuid', 'amount_minor', 'currency', 'destination', 'reason', 'revoked_access', 'created_at']);

        return response()->json([
            'data' => $refunds,
            'meta' => [
                'order_total_minor' => (int) $order->total_minor,
                'refunded_minor' => $this->refunds->refundedTotal($order),
                'refundable_minor' => $this->refunds->refundable($order),
            ],
        ]);
    }
}
