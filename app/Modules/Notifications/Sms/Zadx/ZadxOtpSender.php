<?php

namespace App\Modules\Notifications\Sms\Zadx;

use App\Modules\Notifications\Contracts\OtpSender;
use RuntimeException;

/**
 * Delivers a verification code through ZADX `POST /otp/send`.
 *
 * Code-shaped, not message-shaped: ZADX takes the digits and renders its own
 * pre-approved template (`كود التحقق الخاص بك هو: {otp}`), substituting `{otp}`
 * and `{app_name}` itself. Inline template text is ignored by the server, so the
 * academy's `account.otp.requested` wording does NOT apply on this path — the
 * `$message` argument of the contract is deliberately unused here.
 *
 * That trade is taken on purpose. The alternative, sending the rendered wording
 * through `/sms/send`, puts every login code through ZADX's content screening,
 * where one false positive costs a strike plus 10% of the academy's quota and
 * three strikes suspend the account. `/otp/send` is exempt from screening. A
 * code that cannot be delivered is an account lockout, so it takes the exempt
 * path; per-academy wording can come back later via ZADX's `template_id`, which
 * their admin team provisions per app.
 */
class ZadxOtpSender implements OtpSender
{
    public function __construct(private readonly ZadxClient $client) {}

    public function send(string $to, string $code, ?string $message = null): void
    {
        // ZADX accepts 4 to 6 digits. Our own length is configurable
        // (config/otp.php), so a misconfigured OTP_LENGTH must fail here with a
        // sentence rather than as an opaque 422 from someone else's API.
        if (preg_match('/^\d{4,6}$/', $code) !== 1) {
            throw new RuntimeException('ZADX accepts a 4 to 6 digit code; check OTP_LENGTH.');
        }

        $this->client->post('/otp/send', [
            'to' => ZadxClient::msisdn($to),
            'otp' => $code,
        ], ZadxClient::idempotencyKey('otp', $to, $code));
    }
}
