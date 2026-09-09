<?php

namespace Tests\Support;

/**
 * Builds a Fawry server notification the way Fawry sends it, signed with
 * SHA-256(fawryRefNumber + merchantRefNumber + paymentAmount + orderAmount +
 * orderStatus + paymentMethod + paymentReferenceNumber + secureKey).
 */
class FawryCallback
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function notification(string $merchantRefNumber, string $orderStatus = 'PAID', array $overrides = []): array
    {
        $payload = array_replace([
            'requestId' => 'req-'.substr(md5($merchantRefNumber), 0, 12),
            'fawryRefNumber' => '970177',
            'merchantRefNumber' => $merchantRefNumber,
            'customerMobile' => '01000000123',
            'customerMail' => 'student@example.test',
            'paymentAmount' => '150.00',
            'orderAmount' => '150.00',
            'fawryFees' => '2.00',
            'orderStatus' => $orderStatus,
            'paymentMethod' => 'PAYATFAWRY',
            'paymentRefrenceNumber' => '',
            'orderExpiryDate' => 1533554719314,
        ], $overrides);

        $payload['messageSignature'] = self::signature($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public static function signature(array $payload, ?string $secureKey = null): string
    {
        return hash('sha256', implode('', [
            (string) ($payload['fawryRefNumber'] ?? ''),
            (string) ($payload['merchantRefNumber'] ?? ''),
            (string) ($payload['paymentAmount'] ?? ''),
            (string) ($payload['orderAmount'] ?? ''),
            (string) ($payload['orderStatus'] ?? ''),
            (string) ($payload['paymentMethod'] ?? ''),
            (string) ($payload['paymentRefrenceNumber'] ?? ''),
            $secureKey ?? (string) config('commerce.fawry.secure_key'),
        ]));
    }
}
