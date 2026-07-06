<?php

return [

    'currency' => env('COMMERCE_CURRENCY', 'EGP'),

    // Platform's cut of student sales (PRD open decision D3 — 0% until set).
    'commission_percent' => (float) env('COMMERCE_COMMISSION_PERCENT', 0),

    // Default payment gateway for card/kiosk/wallet top-ups.
    'gateway' => env('COMMERCE_GATEWAY', 'paymob'),

    'paymob' => [
        'hmac_secret' => env('PAYMOB_HMAC_SECRET', 'local-dev-secret'),
    ],

    // Minimum wallet top-up (minor units).
    'min_topup_minor' => (int) env('COMMERCE_MIN_TOPUP_MINOR', 1000),

];
