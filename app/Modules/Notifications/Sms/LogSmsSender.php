<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Dev/default SMS driver: writes the message to the log instead of sending.
 * Lets the full OTP flow work before an aggregator account exists.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::channel(config('logging.default'))->info('[SMS]', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
