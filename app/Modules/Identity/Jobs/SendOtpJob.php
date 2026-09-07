<?php

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Services\Engine\TemplatedSmsNotifier;
use App\Modules\Notifications\Support\RunsInTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers an OTP out-of-band (no user request waits on the aggregator, per
 * 02_Architecture.md §6). The plaintext code lives only in the queue payload
 * and the delivered message — the DB stores just its hash.
 *
 * The academy is carried explicitly and re-bound inside the worker: SMS
 * credentials are per-tenant, so without a bound tenant the driver has no
 * account to send through. The wording comes from the `account.otp.requested`
 * catalog entry, which is what lets an academy send the code in Arabic or
 * reword it; the hard-coded English line is only the fallback for an academy
 * whose copy was removed.
 */
class SendOtpJob implements ShouldQueue
{
    use Queueable, RunsInTenantContext;

    public function __construct(
        public string $identifier,
        public string $channel,
        public OtpPurpose $purpose,
        public string $code,
        public ?int $tenantId = null,
    ) {}

    public function handle(SmsSender $sms, TemplatedSmsNotifier $notifier): void
    {
        if ($this->channel !== 'sms') {
            return; // email OTP arrives with the email channel work
        }

        $this->inTenantContext($this->tenantId, function () use ($sms, $notifier): void {
            $message = $this->tenantId === null
                ? null
                : $notifier->render('account.otp.requested', $this->tenantId, ['otp' => $this->code]);

            $sms->send($this->identifier, $message ?? "Elameed code: {$this->code}");
        });
    }
}
