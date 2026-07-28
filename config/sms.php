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

];
