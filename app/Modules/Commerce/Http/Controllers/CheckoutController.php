<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Gateways\PaymobGateway;
use App\Modules\Commerce\Http\Requests\CartRequest;
use App\Modules\Commerce\Http\Requests\PayRequest;
use App\Modules\Commerce\Http\Resources\OrderResource;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\FulfillOrderService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Checkout (M05/M06): quote → order → pay. Wallet payment is fully functional
 * locally; card (Paymob) returns a hosted-payment redirect and completes via the
 * idempotent webhook.
 */
class CheckoutController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CheckoutService $checkout,
        private readonly LedgerService $ledger,
        private readonly FulfillOrderService $fulfiller,
    ) {}

    public function quote(CartRequest $request): JsonResponse
    {
        $quote = $this->checkout->price($request->validated('items'));

        return response()->json(['data' => [
            'total_minor' => $quote['total_minor'],
            'currency' => $quote['currency'],
            'lines' => array_map(fn ($l) => [
                'type' => $l['item_type'], 'title' => $l['title'], 'price_minor' => $l['price_minor'],
            ], $quote['lines']),
        ]]);
    }

    public function order(CartRequest $request): JsonResponse
    {
        $order = $this->checkout->createOrder($request->user()->getKey(), $request->validated('items'));

        return (new OrderResource($order->load('items')))->response()->setStatusCode(201);
    }

    public function pay(PayRequest $request): JsonResponse
    {
        $order = $this->userOrder($request);

        if ($order->isPaid()) {
            return response()->json(['data' => ['status' => 'paid', 'order' => $order->uuid]]);
        }

        return $request->validated('method') === 'wallet'
            ? $this->payWithWallet($order)
            : $this->payWithPaymob($order);
    }

    private function payWithWallet(Order $order): JsonResponse
    {
        $wallet = $this->ledger->walletFor((int) $order->tenant_id, (int) $order->user_id);

        if ($this->ledger->balance($wallet) < (int) $order->total_minor) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient wallet balance.']);
        }

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount_minor' => $order->total_minor,
            'status' => Payment::STATUS_PAID,
            'processed_at' => now(),
        ]);

        $this->fulfiller->fulfill($order, LedgerEntry::STUDENT_WALLET);

        return response()->json(['data' => ['status' => 'paid', 'order' => $order->fresh()->uuid]]);
    }

    private function payWithPaymob(Order $order): JsonResponse
    {
        $gateway = app(PaymobGateway::class);
        $charge = $gateway->createCharge($order);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => $gateway->name(),
            'amount_minor' => $order->total_minor,
            'status' => Payment::STATUS_PENDING,
            'reference_number' => $charge['reference'],
        ]);

        return response()->json(['data' => [
            'status' => 'pending',
            'order' => $order->uuid,
            'redirect_url' => $charge['redirect_url'],
        ]]);
    }

    private function userOrder(Request $request): Order
    {
        $order = Order::query()
            ->where('uuid', $request->validated('order'))
            ->where('user_id', $request->user()->getKey())
            ->with('items')
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'Order not found.']);
        }

        return $order;
    }
}
