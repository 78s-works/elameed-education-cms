<?php

return [

    // Number of digits in a generated code.
    'length' => (int) env('OTP_LENGTH', 6),

    // Seconds a code stays valid after issuance.
    'ttl' => (int) env('OTP_TTL', 600),

    // Failed verification attempts allowed before a code is burned.
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

];
