<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use RuntimeException;
use Illuminate\Support\Facades\Http;

/**
 * WE Business SMS (Telecom Egypt / connekio) driver — per-tenant, self-service.
 *
 * There is NO platform-wide aggregator account: each tenant supplies its own WE
 * data (username/password/account_id/sender), stored on the tenant's
 * `notification_channel_settings` row for channel `sms` (encrypted `config`).
 * This driver resolves that row for the CURRENT tenant at send time — so one
 * tenant sending never depends on, or leaks into, another's configuration.
 *
 * A tenant that has not configured SMS (no row / `is_active = false` / missing
 * credentials) causes a send to throw; the notification engine turns that into a
 * NotificationFailure, and the OTP job surfaces it as a failed job.
 *
 * API manual: POST /sms/single with Basic base64(username:password:accountid).
 */
class ConnekioSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        $config = $this->tenantConfig();

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $accountId = (string) ($config['account_id'] ?? '');
        $sender = (string) ($config['sender'] ?? '');
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('sms.connekio.base_url')), '/');

        if ($username === '' || $password === '' || $accountId === '' || $sender === '') {
            throw new RuntimeException('SMS is not fully configured for this tenant.');
        }

        // Basic auth per the API manual: base64 of "username:password:accountid".
        $token = base64_encode("{$username}:{$password}:{$accountId}");

        $response = Http::withHeaders(['Authorization' => "Basic {$token}"])
            ->acceptJson()
            ->asJson()
            ->timeout(15) // never let a hung gateway block the queue worker
            ->post("{$baseUrl}/sms/single", [
                'account_id' => (int) $accountId,
                'text' => $message,
                'msisdn' => Msisdn::normalize($to),
                'sender' => $sender,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("WE SMS gateway HTTP {$response->status()}.");
        }

        // Gateway-level rejection is signalled by status=false in a 200 body.
        if ($response->json('status') === false) {
            $reason = (string) ($response->json('status_description') ?? 'rejected');
            throw new RuntimeException("WE SMS gateway rejected the message: {$reason}.");
        }
    }

    /**
     * The current tenant's active SMS channel config. BelongsToTenant scopes the
     * query to the resolved tenant, so no tenant_id is passed (or accepted).
     *
     * @return array<string,mixed>
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

        return $setting->config ?? [];
    }
}
