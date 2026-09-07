<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one mailable behind the engine's `email` channel. The copy is already
 * rendered by the engine (template + translation + interpolation), so this class
 * only wraps it in a direction-aware shell: `ar` renders RTL, everything else
 * LTR.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly string $language = 'ar',
        public readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            using: [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notifications.mail',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyText' => $this->bodyText,
                'direction' => $this->language === 'ar' ? 'rtl' : 'ltr',
                'senderName' => $this->fromName ?: config('app.name', 'Elameed'),
            ],
        );
    }
}
