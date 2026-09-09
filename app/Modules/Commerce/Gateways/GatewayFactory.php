<?php

namespace App\Modules\Commerce\Gateways;

use App\Modules\Commerce\Contracts\PaymentGateway;

/**
 * Resolves the gateway behind a checkout `method` (and behind a webhook route).
 * One place to add the next provider — the checkout and the webhook controller
 * both ask here rather than naming a class.
 */
class GatewayFactory
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const GATEWAYS = [
        'paymob' => PaymobGateway::class,
        'fawry' => FawryGateway::class,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::GATEWAYS);
    }

    public static function supports(string $name): bool
    {
        return isset(self::GATEWAYS[$name]);
    }

    public function make(string $name): PaymentGateway
    {
        return app(self::GATEWAYS[$name] ?? throw new \InvalidArgumentException("Unknown payment gateway [{$name}]."));
    }
}
