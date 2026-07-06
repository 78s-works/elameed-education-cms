<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Gateways\PaymobGateway;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Commerce\Services\FulfillOrderService;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/paymob — signature-verified, idempotent (04_API_Spec §3/§4).
 * Runs on the platform host (no tenant resolved from host); the tenant comes
 * from the referenced order. Verify signature → dedupe on gateway_txn_id →
 * fulfil in a transaction.
 */
class PaymentWebhookController
{
    public function __construct(private readonly FulfillOrderService $fulfiller) {}

    public function paymob(Request $request): JsonResponse
    {
        $gateway = app(PaymobGateway::class);

        if (! $gateway->verifyWebhook($request)) {
            return response()->json(['error' => ['code' => 'invalid_signature', 'message' => 'Bad signature.']], 400);
        }

        $data = $gateway->parseWebhook($request);

        // Idempotency guard #1: this exact gateway transaction already handled.
        $seen = Payment::withoutGlobalScopes()->where('gateway_txn_id', $data['gateway_txn_id'])->first();
        if ($seen !== null && $seen->status === Payment::STATUS_PAID) {
            return response()->json(['data' => ['status' => 'already_processed']]);
        }

        $order = Order::withoutGlobalScopes()->where('uuid', $data['order_uuid'])->with('items')->first();
        if ($order === null) {
            return response()->json(['error' => ['code' => 'order_not_found', 'message' => 'Unknown order.']], 404);
        }

        if ($data['status'] !== 'paid') {
            $this->recordPayment($order, $data, Payment::STATUS_FAILED, $request->all());

            return response()->json(['data' => ['status' => 'failed']]);
        }

        $this->recordPayment($order, $data, Payment::STATUS_PAID, $request->all());

        // Idempotency guard #2: fulfil is a no-op if the order is already paid,
        // and the ledger post dedupes on its operation key.
        $this->fulfiller->fulfill($order, LedgerEntry::GATEWAY_CLEARING);

        return response()->json(['data' => ['status' => 'paid', 'order' => $order->uuid]]);
    }

    private function recordPayment(Order $order, array $data, string $status, array $raw): void
    {
        $payment = Payment::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('gateway', 'paymob')
            ->first() ?? new Payment(['order_id' => $order->id, 'gateway' => 'paymob']);

        $payment->tenant_id = $order->tenant_id;
        $payment->gateway_txn_id = $data['gateway_txn_id'];
        $payment->amount_minor = $data['amount_minor'] ?: $order->total_minor;
        $payment->status = $status;
        $payment->raw_payload = $raw;
        $payment->processed_at = now();
        $payment->save();
    }
}
