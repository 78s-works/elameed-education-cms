<?php

namespace App\Modules\Notifications\Services\Engine;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;

/**
 * Answers one question for the engine and for the broadcast cost preview: can
 * this academy actually deliver on this channel right now?
 *
 * Rules, per channel:
 *   - `database` — always on (the in-app inbox needs no configuration), unless
 *     the tenant explicitly switched it off.
 *   - `sms`      — OFF until the academy stores its own WE Business SMS
 *     credentials and enables them (`PUT /teacher/sms-settings`). There is no
 *     platform aggregator account, so an unconfigured academy must not attempt
 *     a send at all: attempting one produces a NotificationFailure per
 *     recipient and no message.
 *   - `email`    — on unless switched off; delivery uses the platform mailer.
 *   - `push`     — off until a device-token/FCM transport exists.
 *
 * The channel-settings query drops the tenant global scope and passes the id
 * explicitly, so it is also correct from a queue worker or webhook where no
 * tenant is bound to the container.
 */
class ChannelAvailability
{
    /** Credential keys a tenant must fill before SMS can be used. */
    private const SMS_REQUIRED = ['sender', 'username', 'password', 'account_id'];

    /** @var array<string, bool> memoised per (tenant, channel) within a request */
    private array $cache = [];

    public function isAvailable(int $tenantId, NotificationChannel $channel): bool
    {
        $cacheKey = $tenantId.':'.$channel->value;

        return $this->cache[$cacheKey] ??= $this->resolve($tenantId, $channel);
    }

    /** Every channel this academy can currently deliver on. @return list<string> */
    public function availableFor(int $tenantId): array
    {
        $available = [];

        foreach (NotificationChannel::cases() as $channel) {
            if ($this->isAvailable($tenantId, $channel)) {
                $available[] = $channel->value;
            }
        }

        return $available;
    }

    private function resolve(int $tenantId, NotificationChannel $channel): bool
    {
        if (! $channel->isImplemented()) {
            return false;
        }

        $setting = NotificationChannelSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel->value)
            ->first();

        // An explicit kill-switch always wins.
        if ($setting !== null && ! $setting->is_active) {
            return false;
        }

        if ($channel !== NotificationChannel::Sms) {
            return true; // database/email need no per-tenant credentials
        }

        // SMS: the academy must have supplied and enabled its own credentials.
        if ($setting === null) {
            return false;
        }

        $config = $setting->config ?? [];

        foreach (self::SMS_REQUIRED as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }
}
