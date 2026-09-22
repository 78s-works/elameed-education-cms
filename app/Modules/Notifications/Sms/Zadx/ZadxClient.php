<?php

namespace App\Modules\Notifications\Sms\Zadx;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Sms\Msisdn;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport for the ZADX SMS API (EDU-OPS-004) — auth, idempotency and error
 * translation, shared by the OTP and plain-text senders.
 *
 * Per-tenant, self-service, exactly like the WE driver before it: there is no
 * platform-wide ZADX account. Each academy is one ZADX "app" with its own key
 * pair, stored on that academy's `notification_channel_settings` row (channel
 * `sms`, `config` encrypted at rest). An academy with no row / `is_active =
 * false` / missing keys causes a send to throw, and the engine turns that into a
 * NotificationFailure. Note that ZADX apps are provisioned by their admin team,
 * not self-service — onboarding an academy starts with a request to them.
 *
 * API: https://smsportal.zadx.net/docs
 */
class ZadxClient
{
    /**
     * POST a write request. Every ZADX write REQUIRES an Idempotency-Key — a
     * missing one is a 422, not a warning — and the caller owns that key
     * because only the caller knows what "the same logical send" means.
     *
     * Deliberately no HTTP-level retry: ZADX treats a provider timeout as
     * `pending_verification` with credits already reserved and says never to
     * resend it automatically. A queue retry is safe because it reuses the same
     * idempotency key and so returns the original response instead of charging
     * twice.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed> the decoded response body
     */
    public function post(string $path, array $payload, string $idempotencyKey): array
    {
        $config = $this->tenantConfig();

        $payload['sender_id'] ??= $config['sender_id'];

        $response = Http::withHeaders([
            'X-Api-Key' => $config['api_key'],
            'X-Api-Secret' => $config['api_secret'],
            'Idempotency-Key' => $idempotencyKey,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout(15) // never let a hung gateway block the queue worker
            ->post($config['base_url'].$path, array_filter(
                $payload,
                static fn ($value) => $value !== null && $value !== '',
            ));

        $body = $this->body($response, $path);

        // An explicit rejection can also arrive inside a 2xx. Checked only on a
        // write: a read's envelope carries `status: success`, which describes the
        // request rather than any message. `pending_verification` is NOT a
        // rejection — credits are reserved and the message may still land, so it
        // is left to the caller rather than raised as a failure here.
        if (($body['status'] ?? null) === 'failed') {
            throw new RuntimeException("ZADX {$path} returned status failed for message ".($body['id'] ?? '?').'.');
        }

        return $body;
    }

    /**
     * GET a read endpoint (`/sms/balance`, `/sms/sender-ids`, `/messages`).
     * Reads take no idempotency key.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $config = $this->tenantConfig();

        $response = Http::withHeaders([
            'X-Api-Key' => $config['api_key'],
            'X-Api-Secret' => $config['api_secret'],
        ])
            ->acceptJson()
            ->timeout(15)
            ->get($config['base_url'].$path, $query);

        return $this->body($response, $path);
    }

    /**
     * The format ZADX documents for `to`. Msisdn::normalize yields the bare
     * international form (`201…`); ZADX's own examples are `01…` or `+201…`, so
     * the `+` is restored rather than assumed to be optional.
     */
    public static function msisdn(string $raw): string
    {
        return '+'.Msisdn::normalize($raw);
    }

    /**
     * A stable idempotency key for one logical send. Stable is the whole point:
     * the same queued job retried after a worker crash must reuse it, or the
     * academy is charged twice for one code. Hashed so no phone number or
     * message body travels in a header.
     */
    public static function idempotencyKey(string $prefix, string ...$parts): string
    {
        return $prefix.'-'.hash('xxh128', implode('|', $parts));
    }

    /**
     * Turn a response into a body, or into an exception an operator can act on.
     * ZADX answers errors with `{error: {code, message}}`; the CODE is the part
     * that says what to do (`sender_id_not_allowed` and `quota_exhausted` are
     * both "it didn't send" and have nothing else in common), so it is kept in
     * the message rather than flattened away.
     *
     * @return array<string,mixed>
     */
    private function body(Response $response, string $path): array
    {
        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            $code = (string) ($response->json('error.code') ?? 'http_'.$response->status());
            $message = (string) ($response->json('error.message') ?? $response->body());

            throw new RuntimeException(
                "ZADX {$path} failed [{$code}] HTTP {$response->status()}: ".trim($message)
            );
        }

        return $body;
    }

    /**
     * The current tenant's active ZADX config. BelongsToTenant scopes the query
     * to the resolved tenant, so no tenant_id is passed (or accepted).
     *
     * @return array{api_key: string, api_secret: string, sender_id: ?string, base_url: string}
     */
    private function tenantConfig(): array
    {
        $setting = NotificationChannelSetting::query()
            ->where('channel', NotificationChannel::Sms->value)
            ->where('is_active', true)
            ->first();

        if ($setting === null) {
            throw new RuntimeException('SMS is not enabled for this tenant.');
        }

        $config = $setting->config ?? [];

        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('SMS is not fully configured for this tenant.');
        }

        $baseUrl = (string) ($config['base_url'] ?? '');

        if ($baseUrl === '') {
            $baseUrl = (string) config('sms.zadx.base_url');
        }

        $senderId = trim((string) ($config['sender_id'] ?? ''));

        return [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            // Omitted rather than blank: ZADX rejects an unassigned sender id
            // (403 sender_id_not_allowed), and omitting it uses the app default.
            'sender_id' => $senderId === '' ? null : $senderId,
            'base_url' => rtrim($baseUrl, '/'),
        ];
    }
}
