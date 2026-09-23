<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Contracts\OtpSender;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Services\Engine\TemplatedSmsNotifier;
use App\Modules\Notifications\Sms\Msisdn;
use App\Modules\Notifications\Sms\Zadx\ZadxClient;
use App\Modules\Notifications\Support\RunsInTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\HostNormalizer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends ONE real message through whatever driver `config('sms.driver')` binds,
 * as one academy, and reports the gateway's own verdict (EDU-OPS-004).
 *
 * Exists because the go-live check — "register a test student and watch a phone"
 * — cannot distinguish the ways a send fails. ZADX alone answers with a dozen
 * error codes that need different fixes: `sender_id_not_allowed`,
 * `quota_exhausted`, `rate_limited_phone_minute`, `app_inactive`,
 * `missing_idempotency_key`. Through a registration all of them arrive
 * identically — a failed job on the `otp` queue with its message behind
 * `failed_jobs`. That code is exactly what a go-live needs to read.
 *
 * It also leaves no junk student on the launch academy in production.
 *
 * `--otp` exercises the real OTP path (ZADX `/otp/send`, screening-exempt, the
 * gateway's own approved template); without it the plain-text path the
 * notification engine uses is exercised instead. The two can fail independently
 * — an app may be provisioned for one mode only — so a go-live that cares about
 * both has to probe both.
 *
 * Read-only against our own database: no otp_codes row, no user.
 */
class SmsProbeCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'sms:probe
        {--tenant= : The academy to send AS — slug, or one of its hosts. Gateway credentials are per-academy, so this is required.}
        {--phone= : Destination number. Egypt local (01…) or international; normalized before sending.}
        {--otp : Probe the OTP endpoint instead of plain text. The code is thrown away and is never verifiable.}
        {--message= : Override the text. Ignored with --otp on a gateway that renders its own template.}';

    protected $description = 'Send one message through the configured SMS driver and print the gateway verdict';

    public function handle(): int
    {
        $phone = $this->trimmed('phone');
        $slugOrHost = $this->trimmed('tenant');

        if ($phone === null) {
            $this->error('Pass --phone. This command sends a real message; it will not guess a destination.');

            return self::FAILURE;
        }

        if ($slugOrHost === null) {
            $this->error('Pass --tenant. Gateway credentials belong to an academy, not to the platform.');

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant($slugOrHost);

        if ($tenant === null) {
            $this->error("No academy matches --tenant={$slugOrHost} (tried slug, then tenant_domains.host).");

            return self::FAILURE;
        }

        $driver = (string) config('sms.driver');
        $otpMode = (bool) $this->option('otp');

        // Resolved before binding the tenant on purpose: the binding depends on
        // config alone, and naming the class up front is what tells the operator
        // whether the env change actually took. A cached `log` config on an
        // un-restarted worker is the exact failure this task exists to end.
        $sender = $otpMode ? app(OtpSender::class) : app(SmsSender::class);

        $this->line('Academy : '.$tenant->name." (slug: {$tenant->slug}, id: {$tenant->getKey()})");
        $this->line("Driver  : {$driver} → ".$sender::class);
        $this->line('Path    : '.($otpMode ? 'OTP endpoint' : 'plain text'));
        $this->line('To      : '.Msisdn::normalize($phone)." (from {$phone})");

        if ($driver !== 'zadx' && $driver !== 'connekio') {
            $this->warn('This driver writes to the log and sends nothing. Set SMS_DRIVER=zadx, then `php artisan config:clear && php artisan config:cache`.');
        }

        return $this->inTenantContext((int) $tenant->getKey(), function () use ($sender, $tenant, $phone, $otpMode, $driver): int {
            $this->reportBalance($driver, 'before');

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $message = $this->message((int) $tenant->getKey(), $code, $otpMode);

            $this->line('Text    : '.($otpMode ? "code {$code}, wording chosen by the gateway on ZADX" : $message));
            $this->newLine();

            try {
                if ($sender instanceof OtpSender) {
                    $sender->send($phone, $code, $message);
                } else {
                    $sender->send($phone, $message);
                }
            } catch (Throwable $e) {
                // The driver's message IS the finding — the ZADX error code names
                // which of a dozen different fixes applies.
                $this->error('Not sent: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->info('Accepted by the gateway.');
            $this->reportLastMessage($driver);
            $this->reportBalance($driver, 'after');
            $this->newLine();
            $this->line('Acceptance is the handset and the portal log, not this line.');

            return self::SUCCESS;
        });
    }

    /**
     * Credits before and after. A send that is accepted but costs more segments
     * than expected is only visible as the difference — Arabic fits 70
     * characters per segment against 160 for English, and ZADX appends the app
     * name to every plain-text message as a further line.
     */
    private function reportBalance(string $driver, string $when): void
    {
        if ($driver !== 'zadx') {
            return;
        }

        try {
            $balance = app(ZadxClient::class)->get('/sms/balance');
        } catch (Throwable $e) {
            $this->warn("Could not read the balance {$when} the send: ".$e->getMessage());

            return;
        }

        $credits = $balance['remaining_credits'] ?? $balance['credits'] ?? '?';
        $this->line(str_pad("Credits {$when}", 8).': '.$credits);
    }

    /** The row ZADX just wrote, which carries the cost and the delivery status. */
    private function reportLastMessage(string $driver): void
    {
        if ($driver !== 'zadx') {
            return;
        }

        try {
            $messages = app(ZadxClient::class)->get('/messages', ['per_page' => 1, 'page' => 1]);
        } catch (Throwable $e) {
            $this->warn('Could not read the message back: '.$e->getMessage());

            return;
        }

        // `/messages` wraps its page as `data.items[]`; the single-send response
        // is a bare object. Both are read so this keeps working whichever the
        // endpoint returns.
        $last = $messages['data']['items'][0] ?? $messages['data'][0] ?? null;

        if (! is_array($last)) {
            return;
        }

        foreach (['id', 'status', 'delivery_status', 'billing_status', 'sender_id', 'cost_credits', 'error'] as $key) {
            if (array_key_exists($key, $last) && $last[$key] !== null) {
                $this->line(str_pad(ucfirst(str_replace('_', ' ', $key)), 17).': '.$last[$key]);
            }
        }

        // What the handset will actually show — on the OTP endpoint this is the
        // gateway's own template, not the academy's wording, so seeing it is the
        // only way to catch a wrong {app_name} before students do.
        if (isset($last['body'])) {
            $this->line(str_pad('Body', 17).': '.$last['body']);
        }

        if (($last['status'] ?? null) === 'pending_verification') {
            $this->warn('Status is pending_verification: the provider never confirmed. Credits stay reserved and ZADX will not resend it. Check the handset before re-running.');
        }
    }

    /**
     * The academy's own `account.otp.requested` wording, so a message-shaped
     * gateway probes the exact copy a real OTP uses. On ZADX's OTP endpoint the
     * gateway renders its own approved template and this is ignored — which is
     * itself worth printing, because it is the behaviour change of this task.
     */
    private function message(int $tenantId, string $code, bool $otpMode): string
    {
        $override = $this->trimmed('message');

        if ($override !== null && ! $otpMode) {
            return $override;
        }

        $rendered = app(TemplatedSmsNotifier::class)->render('account.otp.requested', $tenantId, ['otp' => $code]);

        if ($rendered === null) {
            $this->warn('This academy has no usable account.otp.requested SMS copy — probing with the built-in fallback wording instead.');
        }

        return $rendered ?? "Elameed code: {$code}";
    }

    /**
     * Slug first, then any host in `tenant_domains` — the two names an operator
     * actually has in front of them (the board says one, the browser the other).
     */
    private function resolveTenant(string $slugOrHost): ?Tenant
    {
        $tenant = Tenant::query()->where('slug', $slugOrHost)->first();

        if ($tenant !== null) {
            return $tenant;
        }

        $host = HostNormalizer::normalize($this->hostPart($slugOrHost));

        if ($host === '') {
            return null;
        }

        // Same www-or-not matching TenantResolver uses, so an academy registered
        // one way resolves when the operator types the other.
        return TenantDomain::query()
            ->whereIn('host', HostNormalizer::candidates($host))
            ->first()?->tenant;
    }

    /**
     * HostNormalizer takes a bare host, because in a request it gets one. An
     * operator copies what the browser shows, so reduce a pasted URL to its host
     * first rather than failing with "no academy matches https://…".
     */
    private function hostPart(string $value): string
    {
        if (str_contains($value, '://')) {
            return (string) (parse_url($value, PHP_URL_HOST) ?? '');
        }

        return explode('/', $value, 2)[0];
    }

    private function trimmed(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }
}
