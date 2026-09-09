<?php

namespace App\Modules\Commerce\Gateways;

use App\Modules\Commerce\Contracts\PaymentGateway;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\Payment;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paymob (Accept) adapter over the unified Intention API.
 *
 * createCharge opens an intention with the secret key and hands the student the
 * hosted Unified Checkout for its `client_secret`; Paymob then POSTs a
 * TRANSACTION callback which `verifyWebhook` authenticates with the documented
 * HMAC-SHA512 scheme before `parseWebhook` normalises it for the webhook
 * controller (02_Architecture.md §8, docs/api/commerce.md).
 *
 * Sandbox and live differ by credentials ONLY — every endpoint, key and
 * integration id comes from `config('commerce.paymob')`, so going live the day
 * the merchant account is approved is an env change with no deploy behind it.
 */
class PaymobGateway implements PaymentGateway
{
    /**
     * The fields Paymob concatenates (in this order, no separator) to sign a
     * TRANSACTION callback. Dotted keys read into the nested payload. Booleans
     * are compared in their JSON form (`true` / `false`).
     *
     * @see https://developers.paymob.com/paymob-docs/developers/webhook-callbacks-and-hmac
     */
    private const HMAC_FIELDS = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order.id',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    /** Separates the order uuid from the attempt counter in `special_reference`. */
    private const REFERENCE_SEPARATOR = '__';

    public function name(): string
    {
        return 'paymob';
    }

    /**
     * Open a payment intention and return the hosted checkout to redirect to.
     *
     * @return array{redirect_url: string, reference: string}
     */
    public function createCharge(Order $order): array
    {
        $config = $this->config();
        $reference = $this->reference($order);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token '.$config['secret_key'],
                'Content-Type' => 'application/json',
            ])
                ->timeout($config['timeout'])
                ->post($config['intention_url'], $this->intentionPayload($order, $reference));
        } catch (ConnectionException $e) {
            Log::error('Paymob intention request failed', ['order' => $order->uuid, 'error' => $e->getMessage()]);

            throw $this->unavailable();
        }

        $clientSecret = (string) $response->json('client_secret', '');

        if ($response->failed() || $clientSecret === '') {
            // The body can carry card/merchant details — log the status and the
            // provider's own error field only.
            Log::error('Paymob rejected the intention', [
                'order' => $order->uuid,
                'status' => $response->status(),
                'detail' => $response->json('detail') ?? $response->json('message'),
            ]);

            throw $this->unavailable();
        }

        return [
            'redirect_url' => $this->checkoutUrl($clientSecret),
            'reference' => $reference,
        ];
    }

    /** Verify the callback signature BEFORE anything is written. */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('commerce.paymob.hmac_secret');

        // Paymob sends the signature as a query parameter; the header is accepted
        // too so a proxy that strips the query string can forward it.
        $provided = (string) ($request->query('hmac') ?? $request->header('X-Paymob-Hmac', ''));

        if ($secret === '' || $provided === '') {
            return false;
        }

        $transaction = $this->transaction($request);

        if ($transaction === []) {
            return false;
        }

        $expected = hash_hmac('sha512', $this->signingString($transaction), $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Normalise the TRANSACTION callback.
     *
     * @return array{gateway_txn_id: string, order_uuid: string, status: string, amount_minor: int}
     */
    public function parseWebhook(Request $request): array
    {
        $transaction = $this->transaction($request);

        return [
            'gateway_txn_id' => (string) ($transaction['id'] ?? ''),
            'order_uuid' => $this->orderUuid($transaction),
            'status' => $this->isPaid($transaction) ? 'paid' : 'failed',
            'amount_minor' => (int) ($transaction['amount_cents'] ?? 0),
        ];
    }

    /**
     * A transaction counts as paid only when it succeeded and nothing later
     * undid it: a void or refund arrives as its own callback on the same order.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function isPaid(array $transaction): bool
    {
        return $this->bool($transaction, 'success')
            && ! $this->bool($transaction, 'pending')
            && ! $this->bool($transaction, 'is_voided')
            && ! $this->bool($transaction, 'is_refunded')
            && ! $this->bool($transaction, 'error_occured');
    }

    /**
     * The order this transaction belongs to. `special_reference` comes back as
     * `order.merchant_order_id`; `extras.creation_extras.order_uuid` is the
     * fallback for a payload that omits it.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function orderUuid(array $transaction): string
    {
        $merchantReference = (string) data_get($transaction, 'order.merchant_order_id', '');

        if ($merchantReference !== '') {
            return explode(self::REFERENCE_SEPARATOR, $merchantReference)[0];
        }

        return (string) data_get($transaction, 'order.extras.creation_extras.order_uuid', '');
    }

    /**
     * `special_reference` must be unique per intention, so a student who
     * abandons a checkout and starts another gets a fresh one — the order uuid
     * plus the attempt number, which `orderUuid()` reads back.
     */
    private function reference(Order $order): string
    {
        $attempts = Payment::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->where('gateway', $this->name())
            ->count();

        return $order->uuid.self::REFERENCE_SEPARATOR.($attempts + 1);
    }

    /**
     * The intention body. Paymob requires an amount in minor units, a currency
     * matching the integration, at least one item and a billing phone number.
     *
     * @return array<string, mixed>
     */
    private function intentionPayload(Order $order, string $reference): array
    {
        $config = $this->config();
        $user = $order->user;

        $items = $order->items->map(fn ($item): array => [
            'name' => (string) $item->title,
            'amount' => (int) $item->price_minor,
            'quantity' => 1,
        ])->all();

        // A coupon discount lives on the order, not on its items, so the item
        // amounts can exceed the total. Paymob charges `amount`; send the order
        // itself as the single line when they disagree.
        if ($items === [] || array_sum(array_column($items, 'amount')) !== (int) $order->total_minor) {
            $items = [[
                'name' => 'Order '.$order->uuid,
                'amount' => (int) $order->total_minor,
                'quantity' => 1,
            ]];
        }

        $payload = [
            'amount' => (int) $order->total_minor,
            'currency' => (string) ($order->currency ?: config('commerce.currency')),
            'payment_methods' => $config['integration_ids'],
            'items' => $items,
            'billing_data' => $this->billingData($order),
            'customer' => [
                'first_name' => $this->firstName($user?->name),
                'last_name' => $this->lastName($user?->name),
                'email' => (string) ($user?->email ?: 'NA'),
                'extras' => ['order_uuid' => $order->uuid],
            ],
            'extras' => ['order_uuid' => $order->uuid],
            'special_reference' => $reference,
        ];

        // Optional per-environment overrides of what the dashboard already holds.
        foreach (['notification_url', 'redirection_url'] as $key) {
            if (! empty($config[$key])) {
                $payload[$key] = $config[$key];
            }
        }

        return $payload;
    }

    /**
     * Paymob validates `billing_data` and rejects empty strings, so every field
     * it insists on falls back to the literal `NA` its own docs use.
     *
     * @return array<string, string>
     */
    private function billingData(Order $order): array
    {
        $user = $order->user;

        return [
            'first_name' => $this->firstName($user?->name),
            'last_name' => $this->lastName($user?->name),
            'phone_number' => (string) ($user?->phone ?: 'NA'),
            'email' => (string) ($user?->email ?: 'NA'),
            'country' => 'EG',
            'city' => 'NA',
            'state' => 'NA',
            'street' => 'NA',
            'building' => 'NA',
            'floor' => 'NA',
            'apartment' => 'NA',
        ];
    }

    private function firstName(?string $name): string
    {
        return $this->nameParts($name)[0] ?? 'NA';
    }

    private function lastName(?string $name): string
    {
        $parts = $this->nameParts($name);

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'NA';
    }

    /** @return array<int, string> */
    private function nameParts(?string $name): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', trim((string) $name)) ?: [],
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function checkoutUrl(string $clientSecret): string
    {
        $config = $this->config();

        return rtrim($config['checkout_url'], '?&').'?'.http_build_query([
            'publicKey' => $config['public_key'],
            'clientSecret' => $clientSecret,
        ]);
    }

    /**
     * The callback body is `{"type": "TRANSACTION", "obj": {…}}`; the signed
     * fields all live under `obj`.
     *
     * @return array<string, mixed>
     */
    private function transaction(Request $request): array
    {
        $obj = $request->input('obj');

        return is_array($obj) ? $obj : [];
    }

    /**
     * Concatenate the signed fields in Paymob's order, with booleans in their
     * JSON form and a missing field as an empty string.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function signingString(array $transaction): string
    {
        $parts = array_map(function (string $field) use ($transaction): string {
            $value = data_get($transaction, $field);

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return $value === null ? '' : (string) $value;
        }, self::HMAC_FIELDS);

        return implode('', $parts);
    }

    /** @param array<string, mixed> $transaction */
    private function bool(array $transaction, string $field): bool
    {
        return filter_var(data_get($transaction, $field), FILTER_VALIDATE_BOOL);
    }

    /** @return array{intention_url: string, checkout_url: string, secret_key: string, public_key: string, integration_ids: array<int, int>, notification_url: ?string, redirection_url: ?string, timeout: int} */
    private function config(): array
    {
        $config = (array) config('commerce.paymob');

        foreach (['secret_key', 'public_key'] as $required) {
            if (empty($config[$required])) {
                Log::error('Paymob is not configured', ['missing' => $required]);

                throw $this->unavailable();
            }
        }

        return [
            'intention_url' => (string) $config['intention_url'],
            'checkout_url' => (string) $config['checkout_url'],
            'secret_key' => (string) $config['secret_key'],
            'public_key' => (string) $config['public_key'],
            'integration_ids' => array_values((array) ($config['integration_ids'] ?? [])),
            'notification_url' => $config['notification_url'] ?? null,
            'redirection_url' => $config['redirection_url'] ?? null,
            'timeout' => (int) ($config['timeout'] ?? 15),
        ];
    }

    private function unavailable(): DomainException
    {
        return new DomainException(
            'payment_gateway_unavailable',
            __('Card payment is unavailable right now. Please try again shortly.'),
            503,
        );
    }
}
