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
     * Begin a hosted payment for an order.
     *
     * @return array{redirect_url: string, reference: string}
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
