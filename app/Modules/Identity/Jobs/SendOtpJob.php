<?php

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Notifications\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers an OTP out-of-band (no user request waits on the aggregator, per
 * 02_Architecture.md §6). The plaintext code lives only in the queue payload
 * and the delivered message — the DB stores just its hash.
 */
class SendOtpJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $identifier,
        public string $channel,
        public OtpPurpose $purpose,
        public string $code,
    ) {}

    public function handle(SmsSender $sms): void
    {
        $message = "Elameed code: {$this->code}";

        if ($this->channel === 'sms') {
            $sms->send($this->identifier, $message);
        }

        // email channel + per-teacher templates arrive in P1.5.
    }
}
