<?php

namespace App\Modules\Commerce\Gateways;

use App\Modules\Commerce\Contracts\PaymentGateway;
use App\Modules\Commerce\Models\Order;
use Illuminate\Http\Request;

/**
 * Paymob adapter. STUB for P1: the merchant account isn't live yet, so
 * createCharge returns a placeholder hosted-payment URL and the webhook is
 * verified with a shared HMAC secret. The contract + idempotent webhook handling
 * are real; swap the internals for Paymob's Intention/HMAC API when the account
 * is approved (02_Architecture.md §8, Roadmap risk: "build against sandbox").
 */
class PaymobGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'paymob';
    }

    public function createCharge(Order $order): array
    {
        $reference = 'pmb_'.$order->uuid;

        return [
            // Real impl returns Paymob's iframe/checkout URL.
            'redirect_url' => url("/pay/paymob/{$order->uuid}"),
            'reference' => $reference,
        ];
    }

    public function verifyWebhook(Request $request): bool
    {
        $provided = (string) $request->header('X-Paymob-Hmac', $request->input('hmac', ''));
        $expected = hash_hmac('sha512', $this->signingString($request), (string) config('commerce.paymob.hmac_secret'));

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(Request $request): array
    {
        return [
            'gateway_txn_id' => (string) $request->input('transaction_id'),
            'order_uuid' => (string) $request->input('order_uuid'),
            'status' => $request->boolean('success') ? 'paid' : 'failed',
            'amount_minor' => (int) $request->input('amount_cents'),
        ];
    }

    /** Deterministic string the HMAC is computed over (kept simple for the stub). */
    private function signingString(Request $request): string
    {
        return implode('|', [
            (string) $request->input('transaction_id'),
            (string) $request->input('order_uuid'),
            (string) $request->input('amount_cents'),
            $request->boolean('success') ? 'true' : 'false',
        ]);
    }
}
