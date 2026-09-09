<?php

namespace App\Modules\Commerce\Contracts;

use App\Modules\Commerce\Models\Order;
use Illuminate\Http\Request;

/**
 * Payment provider abstraction (02_Architecture.md §8 — "All behind a
 * PaymentGateway interface"). Paymob first, Fawry fast-follow, both swappable.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Begin a payment for an order.
     *
     * `redirect_url` is the hosted page to send the student to, and is null for
     * a gateway that answers with a payable reference instead (Fawry). Extra
     * keys a gateway needs to surface — `expires_at`, `gateway_reference` — ride
     * along and the checkout response passes them through when present.
     *
     * @return array{redirect_url: ?string, reference: string, expires_at?: ?string, gateway_reference?: ?string}
     */
    public function createCharge(Order $order): array;

    /** Verify the provider's webhook signature BEFORE processing. */
    public function verifyWebhook(Request $request): bool;

    /**
     * Normalise a webhook payload.
     *
     * @return array{gateway_txn_id: string, order_uuid: string, status: string, amount_minor: int}
     */
    public function parseWebhook(Request $request): array;
}
