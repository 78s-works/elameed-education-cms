<?php

return [

    /*
    | Which SMS driver to use. `log` writes messages to the log (dev). `zadx` is
    | the live gateway (EDU-OPS-004); `connekio` is the older WE Business SMS
    | driver, kept so a tenant still on WE can be switched back by env alone.
    |
    | Every driver is per-tenant and self-service: each tenant stores its own
    | gateway credentials on its notification_channel_settings row, so there are
    | no platform-wide aggregator credentials here. An unrecognised value falls
    | back to `log`, which sends nothing — see the binding in
    | NotificationsServiceProvider.
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
    | ZADX. Only the base URL lives here (a fallback when a tenant omits it);
    | api_key/api_secret/sender_id are per-tenant. One ZADX "app" per academy,
    | each with its own key pair — apps are provisioned by the ZADX admin team,
    | not self-service, so onboarding an academy is a request to them first.
    */
    'zadx' => [
        'base_url' => env('SMS_ZADX_BASE_URL', 'https://smsapi.zadx.net/api/v1'),
    ],

    /*
    | Fallback price of ONE SMS segment, in minor units (piastres), used by the
    | custom-notification cost preview when an academy has not stored its own
    | contract price on its sms channel settings (`price_per_segment_minor`).
    */
    'price_per_segment_minor' => (int) env('SMS_PRICE_PER_SEGMENT_MINOR', 0),

    'currency' => env('SMS_CURRENCY', 'EGP'),

];
