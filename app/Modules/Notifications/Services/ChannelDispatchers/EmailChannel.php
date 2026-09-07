<?php

namespace App\Modules\Notifications\Services\ChannelDispatchers;

use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Support\ChannelResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email channel (doc 10 §7). Sends the rendered copy through the PLATFORM mailer
 * — unlike SMS there is no per-academy account to configure, so email works for
 * every tenant as soon as the deploy has a working `MAIL_MAILER`. The academy
 * name is used as the from-name so the message still reads as the teacher's.
 *
 * Like SmsChannel, a successful send is persisted as a `NotificationMessage`
 * (already read — email has no inbox read-state) plus a `NotificationLog`, so
 * the admin event drill-down shows email deliveries next to the others.
 */
class EmailChannel implements NotificationChannelInterface
{
    public function send(
        NotificationEvent $event,
        User $user,
        string $title,
        string $body,
        array $context = [],
    ): ChannelResult {
        $to = trim((string) $user->email);

        if ($to === '') {
            return ChannelResult::fail('Recipient has no email address.');
        }

        $subject = trim($title) !== '' ? $title : (string) config('app.name', 'Elameed');
        $language = (string) ($context['language'] ?? config('tenancy.default_locale', 'ar'));

        try {
            Mail::to($to)->send(new NotificationMail(
                subjectLine: $subject,
                bodyText: $body,
                language: $language,
                fromName: $context['tenant_name'] ?? null,
            ));
        } catch (Throwable $e) {
            return ChannelResult::fail($e->getMessage());
        }

        $message = new NotificationMessage([
            'notification_event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'channel' => NotificationChannel::Email->value,
            'title' => $subject,
            'body' => $body,
            'is_read' => true, // outbound record; no inbox read-state for email
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
