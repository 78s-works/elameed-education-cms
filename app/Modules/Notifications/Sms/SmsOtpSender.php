<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\OtpSender;
use App\Modules\Notifications\Contracts\SmsSender;

/**
 * The OTP path for message-shaped gateways (the log driver, WE/Connekio): there
 * is no dedicated OTP endpoint, so the code travels as ordinary text.
 *
 * This is the behaviour the OTP flow had before ZADX, kept intact — the academy's
 * own `account.otp.requested` wording is rendered by the caller and sent as-is,
 * which is why `$message` is preferred and `$code` is only the fallback for an
 * academy whose copy was removed.
 */
class SmsOtpSender implements OtpSender
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $to, string $code, ?string $message = null): void
    {
        $this->sms->send($to, $message ?? "Elameed code: {$code}");
    }
}
