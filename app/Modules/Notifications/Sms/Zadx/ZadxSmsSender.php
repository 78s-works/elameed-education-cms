<?php

namespace App\Modules\Notifications\Sms\Zadx;

use App\Modules\Notifications\Contracts\SmsSender;

/**
 * Plain transactional SMS through ZADX `POST /sms/send` — the notification
 * engine's channel (absence alerts, broadcasts, subscription reminders).
 *
 * Two things differ from the WE driver and are visible to academies:
 *
 *  - ZADX appends the app name as a mandatory last line of every message, so a
 *    sent message is always one line longer than the composed one. That line
 *    counts toward billing.
 *  - This endpoint is content-screened. A violation is blocked before any credit
 *    is reserved, adds a strike and deducts 10% of the academy's quota; the
 *    third strike suspends the app. The screening verdict arrives as a normal
 *    error envelope, so it surfaces as a NotificationFailure like any other
 *    rejection — but it is the one failure that also costs quota.
 *
 * Login codes do not come through here; they take the screening-exempt
 * ZadxOtpSender path.
 */
class ZadxSmsSender implements SmsSender
{
    public function __construct(private readonly ZadxClient $client) {}

    public function send(string $to, string $message): void
    {
        $this->client->post('/sms/send', [
            'to' => ZadxClient::msisdn($to),
            'message' => $message,
        ], ZadxClient::idempotencyKey('sms', $to, $message));
    }
}
