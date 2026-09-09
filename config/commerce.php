<?php

return [

    'currency' => env('COMMERCE_CURRENCY', 'EGP'),

    // Platform's cut of student sales (PRD open decision D3 — 0% until set).
    'commission_percent' => (float) env('COMMERCE_COMMISSION_PERCENT', 0),

    // Default payment gateway for card/kiosk/wallet top-ups.
    'gateway' => env('COMMERCE_GATEWAY', 'paymob'),

    // Paymob (Accept). Sandbox and live differ ONLY by these credentials, so
    // going live is an env change — no code path switches on the environment.
    'paymob' => [
        // Full endpoint URLs so a Paymob host change stays an env edit.
        'intention_url' => env('PAYMOB_INTENTION_URL', 'https://accept.paymob.com/v1/intention/'),
        'checkout_url' => env('PAYMOB_CHECKOUT_URL', 'https://accept.paymob.com/unifiedcheckout/'),

        // Secret key authenticates the Intention call; the public key rides on the
        // checkout URL; the HMAC secret verifies the callback. All env-only.
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET', 'local-dev-secret'),

        // Integration ids of the payment methods offered on the checkout
        // (card, wallet, kiosk…), comma-separated in the env.
        'integration_ids' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('PAYMOB_INTEGRATION_IDS', '')),
        ))),

        // Where Paymob sends the student back, and where it POSTs the callback.
        // Both default to this app; set them per environment.
        'redirection_url' => env('PAYMOB_REDIRECTION_URL'),
        'notification_url' => env('PAYMOB_NOTIFICATION_URL'),

        'timeout' => (int) env('PAYMOB_TIMEOUT', 15),
    ],

    // Minimum wallet top-up (minor units).
    'min_topup_minor' => (int) env('COMMERCE_MIN_TOPUP_MINOR', 1000),

];
