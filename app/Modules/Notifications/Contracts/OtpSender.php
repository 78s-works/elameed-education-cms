<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Delivers a verification code. Separate from SmsSender because gateways split
 * into two incompatible shapes and the OTP path must work on both:
 *
 *  - Message-shaped (WE/Connekio, the log driver): you render the wording and
 *    hand over a finished string.
 *  - Code-shaped (ZADX `POST /otp/send`): you hand over the CODE and the gateway
 *    renders its own pre-approved template. It will not accept arbitrary text on
 *    that endpoint, and a code-shaped driver cannot recover the digits from a
 *    rendered sentence.
 *
 * So both are passed. Each implementation uses the one its API takes and
 * documents which it ignores — an adapter seam, not redundancy.
 *
 * Why the OTP gets its own path at all: on ZADX the OTP endpoint is exempt from
 * content screening, while the plain-text endpoint is not, and one screening
 * violation costs a strike plus 10% of the academy's quota. Login codes are the
 * traffic that must never be caught by that.
 */
interface OtpSender
{
    /**
     * @param  string  $to  Recipient phone, in whatever format it was stored.
     * @param  string  $code  The plain code. Used by code-shaped gateways.
     * @param  string|null  $message  The academy's rendered wording, when it has
     *                                usable copy. Used by message-shaped gateways.
     *
     * @throws \RuntimeException when the code was not accepted for delivery.
     */
    public function send(string $to, string $code, ?string $message = null): void;
}
