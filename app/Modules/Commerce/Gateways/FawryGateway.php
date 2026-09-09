<?php

namespace App\Modules\Commerce\Gateways;

use App\Modules\Commerce\Contracts\PaymentGateway;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\Payment;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fawry (Accept "PAYATFAWRY"): the student is handed a reference number and
 * pays cash at any Fawry outlet — how an unbanked parent pays. There is no
 * redirect and no card: `createCharge` books the charge and returns the
 * reference plus the moment it expires, and Fawry POSTs a server notification
 * when the money is actually collected.
 *
 * Signatures are SHA-256 over concatenated fields plus the merchant secure key,
 * in Fawry's documented order (see the constants below). Sandbox and live differ
 * by `config('commerce.fawry')` only, so going live is an env change.
 */
class FawryGateway implements PaymentGateway
{
    /** Charge endpoint, relative to the configured host. */
    private const CHARGE_PATH = '/ECommerceWeb/Fawry/payments/charge';

    /** Payment-status endpoint, used by the reconciliation command. */
    private const STATUS_PATH = '/ECommerceWeb/Fawry/payments/status/v2';

    private const PAYMENT_METHOD = 'PAYATFAWRY';

    /** Fawry's own word for a collected payment. */
    public const STATUS_PAID = 'PAID';

    /** …and for a reference nobody paid before it lapsed. */
    public const STATUS_EXPIRED = 'EXPIRED';

    /** Separates the order reference from the attempt counter. */
    private const REFERENCE_SEPARATOR = '-a';

    public function name(): string
    {
        return 'fawry';
    }

    /**
     * Book a charge and return the reference the student pays against.
     *
     * @return array{redirect_url: ?string, reference: string, expires_at: ?string, gateway_reference: ?string}
     */
    public function createCharge(Order $order): array
    {
        $config = $this->config();
        $merchantRefNum = $this->merchantReference($order);
        $expiresAt = Carbon::now()->addHours($config['expiry_hours']);

        try {
            $response = Http::timeout($config['timeout'])
                ->acceptJson()
                ->post($config['base_url'].self::CHARGE_PATH, $this->chargePayload($order, $merchantRefNum, $expiresAt));
        } catch (ConnectionException $e) {
            Log::error('Fawry charge request failed', ['order' => $order->uuid, 'error' => $e->getMessage()]);

            throw $this->unavailable();
        }

        $referenceNumber = (string) $response->json('referenceNumber', '');

        // Fawry answers 200 with a statusCode in the body, so the HTTP status
        // alone does not say the charge was booked.
        if ($response->failed() || (int) $response->json('statusCode', 0) !== 200 || $referenceNumber === '') {
            Log::error('Fawry rejected the charge', [
                'order' => $order->uuid,
                'status' => $response->status(),
                'statusCode' => $response->json('statusCode'),
                'detail' => $response->json('statusDescription'),
            ]);

            throw $this->unavailable();
        }

        // Fawry's own expiry wins when it sends one (epoch milliseconds).
        $expiration = $response->json('expirationTime');

        return [
            'redirect_url' => null, // cash at an outlet — nothing to redirect to
            'reference' => $merchantRefNum,
            'expires_at' => ($expiration ? Carbon::createFromTimestampMs((int) $expiration) : $expiresAt)->toIso8601String(),
            'gateway_reference' => $referenceNumber,
        ];
    }

    /**
     * Verify the server notification. Fawry signs it with
     * SHA-256(fawryRefNumber + merchantRefNum + paymentAmount + orderAmount +
     * orderStatus + paymentMethod + paymentReferenceNumber + secureKey).
     */
    public function verifyWebhook(Request $request): bool
    {
        $secureKey = (string) config('commerce.fawry.secure_key');
        $provided = (string) $request->input('messageSignature', '');

        if ($secureKey === '' || $provided === '') {
            return false;
        }

        $expected = hash('sha256', implode('', [
            (string) $request->input('fawryRefNumber', ''),
            $this->merchantRefFrom($request),
            (string) $request->input('paymentAmount', ''),
            (string) $request->input('orderAmount', ''),
            (string) $request->input('orderStatus', ''),
            (string) $request->input('paymentMethod', ''),
            (string) $request->input('paymentRefrenceNumber', $request->input('paymentReferenceNumber', '')),
            $secureKey,
        ]));

        return hash_equals($expected, $provided);
    }

    /**
     * @return array{gateway_txn_id: string, order_uuid: string, status: string, amount_minor: int}
     */
    public function parseWebhook(Request $request): array
    {
        $merchantRef = $this->merchantRefFrom($request);
        $orderStatus = strtoupper((string) $request->input('orderStatus', ''));

        return [
            // Fawry's own reference for the collected payment; unique per charge.
            'gateway_txn_id' => (string) $request->input('fawryRefNumber', ''),
            'order_uuid' => $this->orderUuid($merchantRef),
            'status' => match ($orderStatus) {
                self::STATUS_PAID => 'paid',
                self::STATUS_EXPIRED => 'expired',
                default => 'failed',
            },
            'amount_minor' => $this->minorUnits($request->input('orderAmount')),
        ];
    }

    /**
     * Ask Fawry what happened to one booked charge — the reconciliation path for
     * a notification that never arrived.
     *
     * @return array{status: string, gateway_txn_id: string, amount_minor: int}|null null when Fawry has no answer
     */
    public function paymentStatus(string $merchantRefNum): ?array
    {
        $config = $this->config();

        $signature = hash('sha256', $config['merchant_code'].$merchantRefNum.$config['secure_key']);

        try {
            $response = Http::timeout($config['timeout'])
                ->acceptJson()
                ->get($config['base_url'].self::STATUS_PATH, [
                    'merchantCode' => $config['merchant_code'],
                    'merchantRefNumber' => $merchantRefNum,
                    'signature' => $signature,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Fawry status request failed', ['reference' => $merchantRefNum, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed() || (int) $response->json('statusCode', 0) !== 200) {
            return null;
        }

        $orderStatus = strtoupper((string) $response->json('orderStatus', ''));

        return [
            'status' => match ($orderStatus) {
                self::STATUS_PAID => 'paid',
                self::STATUS_EXPIRED => 'expired',
                '' => 'unknown',
                default => 'pending',
            },
            'gateway_txn_id' => (string) $response->json('fawryRefNumber', ''),
            'amount_minor' => $this->minorUnits($response->json('orderAmount')),
        ];
    }

    /**
     * The charge body. Fawry wants money as a decimal string and the expiry as
     * epoch milliseconds; `chargeItems` must add up to `amount`.
     *
     * @return array<string, mixed>
     */
    private function chargePayload(Order $order, string $merchantRefNum, Carbon $expiresAt): array
    {
        $config = $this->config();
        $user = $order->user;
        $amount = $this->decimal((int) $order->total_minor);

        $items = $order->items->map(fn ($item): array => [
            'itemId' => (string) $item->getKey(),
            'description' => Str::limit((string) $item->title, 50, ''),
            'price' => $this->decimal((int) $item->price_minor),
            'quantity' => 1,
        ])->all();

        // A coupon discount lives on the order, so the lines can outrun the
        // total; Fawry rejects that. Send the order as one line instead.
        if ($items === [] || $this->decimal($order->items->sum('price_minor')) !== $amount) {
            $items = [[
                'itemId' => $order->uuid,
                'description' => 'Order '.$order->uuid,
                'price' => $amount,
                'quantity' => 1,
            ]];
        }

        return [
            'merchantCode' => $config['merchant_code'],
            'merchantRefNum' => $merchantRefNum,
            'customerProfileId' => (string) $order->user_id,
            'customerMobile' => (string) ($user?->phone ?? ''),
            'customerEmail' => (string) ($user?->email ?? ''),
            'customerName' => (string) ($user?->name ?? ''),
            'paymentMethod' => self::PAYMENT_METHOD,
            'amount' => $amount,
            'currencyCode' => (string) ($order->currency ?: config('commerce.currency')),
            'description' => 'Order '.$order->uuid,
            'paymentExpiry' => $expiresAt->getTimestampMs(),
            'chargeItems' => $items,
            'language' => 'ar-eg',
            // SHA-256(merchantCode + merchantRefNum + customerProfileId +
            // paymentMethod + amount + cardToken("") + secureKey) — the card
            // token is empty for a cash reference.
            'signature' => hash('sha256', implode('', [
                $config['merchant_code'],
                $merchantRefNum,
                (string) $order->user_id,
                self::PAYMENT_METHOD,
                $amount,
                '',
                $config['secure_key'],
            ])),
        ];
    }

    /**
     * `merchantRefNum` must be unique per charge, and Fawry keeps it short: the
     * order uuid without its dashes, plus an attempt counter when the student
     * comes back for a second reference.
     */
    private function merchantReference(Order $order): string
    {
        $attempts = Payment::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->where('gateway', $this->name())
            ->count();

        $base = str_replace('-', '', (string) $order->uuid);

        return $attempts === 0 ? $base : $base.self::REFERENCE_SEPARATOR.($attempts + 1);
    }

    /** The order behind a merchant reference — by the payment row, else by rebuilding the uuid. */
    private function orderUuid(string $merchantRef): string
    {
        if ($merchantRef === '') {
            return '';
        }

        $payment = Payment::withoutGlobalScopes()
            ->where('gateway', $this->name())
            ->where('reference_number', $merchantRef)
            ->with('order')
            ->first();

        if ($payment?->order !== null) {
            return (string) $payment->order->uuid;
        }

        $hex = explode(self::REFERENCE_SEPARATOR, $merchantRef)[0];

        if (! preg_match('/^[0-9a-f]{32}$/i', $hex)) {
            return '';
        }

        return implode('-', [
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20),
        ]);
    }

    /** Fawry sends both spellings depending on the notification version. */
    private function merchantRefFrom(Request $request): string
    {
        return (string) $request->input('merchantRefNumber', $request->input('merchantRefNum', ''));
    }

    /** Minor units are the internal currency; Fawry talks in decimals. */
    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function minorUnits(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @return array{base_url: string, merchant_code: string, secure_key: string, expiry_hours: int, timeout: int} */
    private function config(): array
    {
        $config = (array) config('commerce.fawry');

        foreach (['merchant_code', 'secure_key'] as $required) {
            if (empty($config[$required])) {
                Log::error('Fawry is not configured', ['missing' => $required]);

                throw $this->unavailable();
            }
        }

        return [
            'base_url' => rtrim((string) $config['base_url'], '/'),
            'merchant_code' => (string) $config['merchant_code'],
            'secure_key' => (string) $config['secure_key'],
            'expiry_hours' => max(1, (int) ($config['expiry_hours'] ?? 72)),
            'timeout' => (int) ($config['timeout'] ?? 15),
        ];
    }

    private function unavailable(): DomainException
    {
        return new DomainException(
            'payment_gateway_unavailable',
            __('Fawry payment is unavailable right now. Please try again shortly.'),
            503,
        );
    }
}
