<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Gateways\GatewayFactory;
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
 * locally; a gateway payment leaves the order pending and completes via the
 * idempotent webhook — Paymob answers with a hosted-checkout redirect, Fawry
 * with a reference number the student pays at an outlet.
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
        $quote = $this->checkout->price($request->validated('items'), $request->validated('coupon'));

        return response()->json(['data' => [
            'subtotal_minor' => $quote['subtotal_minor'],
            'discount_minor' => $quote['discount_minor'],
            'total_minor' => $quote['total_minor'],
            'currency' => $quote['currency'],
            'coupon' => $quote['coupon']?->code,
            'lines' => array_map(fn ($l) => [
                'type' => $l['item_type'], 'title' => $l['title'], 'price_minor' => $l['price_minor'],
                // Access window of THIS line, so the buy screen can state the terms
                // of sale before the student pays. Absent on a wallet top-up.
                'access_terms' => $l['access_terms'] ?? null,
            ], $quote['lines']),
        ]]);
    }

    /**
     * Validate a coupon against a cart without creating an order (M21). Returns
     * the discount when valid; an invalid/expired/used-up code yields 422.
     */
    public function validateCoupon(CartRequest $request): JsonResponse
    {
        $quote = $this->checkout->price($request->validated('items'), $request->validated('coupon'));

        return response()->json(['data' => [
            'valid' => $quote['coupon'] !== null,
            'coupon' => $quote['coupon']?->code,
            'discount_minor' => $quote['discount_minor'],
            'total_minor' => $quote['total_minor'],
        ]]);
    }

    public function order(CartRequest $request): JsonResponse
    {
        $order = $this->checkout->createOrder(
            $request->user()->getKey(),
            $request->validated('items'),
            $request->validated('coupon'),
        );

        return (new OrderResource($order->load('items', 'coupon')))->response()->setStatusCode(201);
    }

    public function pay(PayRequest $request): JsonResponse
    {
        $order = $this->userOrder($request);

        if ($order->isPaid()) {
            return response()->json(['data' => ['status' => 'paid', 'order' => $order->uuid]]);
        }

        $method = (string) $request->validated('method');

        return $method === 'wallet'
            ? $this->payWithWallet($order)
            : $this->payWithGateway($order, $method);
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

    private function payWithGateway(Order $order, string $method): JsonResponse
    {
        $gateway = app(GatewayFactory::class)->make($method);
        $charge = $gateway->createCharge($order);
        $expiresAt = $charge['expires_at'] ?? null;

        Payment::create([
            'order_id' => $order->id,
            'gateway' => $gateway->name(),
            'amount_minor' => $order->total_minor,
            'status' => Payment::STATUS_PENDING,
            'reference_number' => $charge['reference'],
            'expires_at' => $expiresAt,
        ]);

        return response()->json(['data' => array_filter([
            'status' => 'pending',
            'order' => $order->uuid,
            'gateway' => $gateway->name(),
            // Paymob sends the student to a hosted page; Fawry hands over a
            // reference number to pay at an outlet before it expires.
            'redirect_url' => $charge['redirect_url'],
            'reference_number' => $charge['gateway_reference'] ?? null,
            'expires_at' => $expiresAt,
        ], static fn ($value): bool => $value !== null)]);
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
