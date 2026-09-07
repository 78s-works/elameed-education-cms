<?php

return [

    /*
    | Which SMS driver to use. `log` writes messages to the log (dev). `connekio`
    | is the WE Business SMS (Telecom Egypt) driver — per-tenant and self-service:
    | each tenant stores its own WE credentials on its notification_channel_settings
    | row, so there are no platform-wide aggregator credentials here.
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', 'Elameed'),

    /*
    | WE Connekio defaults. Only the base URL lives here (a fallback when a
    | tenant omits it); username/password/account_id/sender are per-tenant.
    */
    'connekio' => [
        'base_url' => env('SMS_CONNEKIO_BASE_URL', 'https://weapi.connekio.com'),
    ],

    /*
    | Fallback price of ONE SMS segment, in minor units (piastres), used by the
    | custom-notification cost preview when an academy has not stored its own
    | contract price on its sms channel settings (`price_per_segment_minor`).
    */
    'price_per_segment_minor' => (int) env('SMS_PRICE_PER_SEGMENT_MINOR', 0),

    'currency' => env('SMS_CURRENCY', 'EGP'),

];
