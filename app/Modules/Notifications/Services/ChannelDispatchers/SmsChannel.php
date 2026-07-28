<?php

namespace App\Modules\Notifications\Services\ChannelDispatchers;

use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Support\ChannelResult;
use Throwable;

/**
 * SMS channel (doc 10 §7). Validates the recipient number, sends via the
 * pluggable SmsSender (per-tenant sender config lands with a real aggregator —
 * for now the `sms.driver` binding, LogSmsSender by default), then persists a
 * `NotificationMessage` + `NotificationLog`. A send error returns a failure so
 * the engine records a `NotificationFailure`.
 */
class SmsChannel implements NotificationChannelInterface
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult {
        $to = trim((string) $user->phone);

        if ($to === '') {
            return ChannelResult::fail('Recipient has no phone number.');
        }

        $text = trim($title.' '.$body);

        try {
            $this->sender->send($to, $text);
        } catch (Throwable $e) {
            return ChannelResult::fail($e->getMessage());
        }

        $message = new NotificationMessage([
            'notification_event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'channel' => NotificationChannel::Sms->value,
            'title' => $title,
            'body' => $body,
            'is_read' => true, // outbound record; no inbox read-state for sms
        ]);
        $message->tenant_id = $event->tenant_id;
        $message->save();

        NotificationLog::create([
            'notification_id' => $message->getKey(),
            'status' => 'sent',
            'metadata' => ['to' => $to],
        ]);

        return ChannelResult::ok(['notification_id' => $message->getKey()]);
    }
}
