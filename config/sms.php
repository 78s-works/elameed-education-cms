<?php

return [

    /*
    | Which SMS driver to use. No aggregator account exists yet, so `log`
    | writes messages to the log (dev). A real driver (SMSMisr / Victory Link /
    | Twilio) is added behind the same SmsSender contract in Phase 1.
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', 'Elameed'),

];
