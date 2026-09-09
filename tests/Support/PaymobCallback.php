<?php

namespace Tests\Support;

/**
 * Builds a Paymob TRANSACTION callback the way Paymob sends it — the signed
 * fields under `obj`, and the HMAC-SHA512 over those fields in Paymob's order.
 */
class PaymobCallback
{
    /** Same order as PaymobGateway::HMAC_FIELDS (Paymob's documented list). */
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

    /**
     * @param  array<string, mixed>  $overrides  merged into the `obj` payload
     * @return array<string, mixed>
     */
    public static function transaction(string $orderReference, int $amountMinor = 15000, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 987654321,
            'amount_cents' => $amountMinor,
            'created_at' => '2026-09-09T10:00:00.000000',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'integration_id' => 1111,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'owner' => 4242,
            'pending' => false,
            'success' => true,
            'order' => [
                'id' => 555111,
                'merchant_order_id' => $orderReference,
            ],
            'source_data' => [
                'pan' => '2346',
                'sub_type' => 'MasterCard',
                'type' => 'card',
            ],
        ], $overrides);
    }

    /** The full callback body. */
    public static function body(array $transaction): array
    {
        return ['type' => 'TRANSACTION', 'obj' => $transaction];
    }

    /** The signature Paymob puts in the callback's `hmac` query parameter. */
    public static function hmac(array $transaction, ?string $secret = null): string
    {
        $parts = array_map(static function (string $field) use ($transaction): string {
            $value = data_get($transaction, $field);

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return $value === null ? '' : (string) $value;
        }, self::HMAC_FIELDS);

        return hash_hmac('sha512', implode('', $parts), $secret ?? (string) config('commerce.paymob.hmac_secret'));
    }
}
