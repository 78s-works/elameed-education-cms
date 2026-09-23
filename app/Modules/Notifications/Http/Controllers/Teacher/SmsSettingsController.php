<?php

namespace App\Modules\Notifications\Http\Controllers\Teacher;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Http\Requests\UpdateSmsSettingsRequest;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/sms-settings — a teacher manages his own academy's SMS
 * gateway credentials. This is the "each academy adds its own data" surface: the
 * platform holds no aggregator account, so SMS only works for an academy once
 * its teacher fills this in and enables it.
 *
 * Two providers are supported. ZADX is the current one (EDU-OPS-004); the WE
 * Business SMS (Connekio) fields stay so an academy already sending on WE keeps
 * working until it is moved over. `provider` decides which set is required.
 *
 * Stored on the current tenant's `notification_channel_settings` row for the
 * `sms` channel (BelongsToTenant scopes + auto-fills tenant_id; `config` is
 * encrypted at rest). Secrets are write-only — never returned.
 */
class SmsSettingsController
{
    /** Credential fields per provider. The first element of each pair is the secret. */
    private const SECRETS = ['zadx' => 'api_secret', 'connekio' => 'password'];

    private const REQUIRED = [
        // sender_id is intentionally absent: omitting it uses the ZADX app's own
        // default sender, which is valid, while a wrong one is a hard 403.
        'zadx' => ['api_key', 'api_secret'],
        'connekio' => ['sender', 'username', 'password', 'account_id'],
    ];

    private const PUBLIC_FIELDS = [
        'zadx' => ['api_key', 'sender_id', 'base_url'],
        'connekio' => ['sender', 'username', 'account_id', 'base_url'],
    ];

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->setting())]);
    }

    public function update(UpdateSmsSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $setting = $this->setting();
        $config = $setting->config ?? [];

        // Omitting `provider` keeps the stored one, so a teacher can flip the
        // switch or edit one field without re-declaring which gateway he is on.
        $provider = $data['provider'] ?? $this->provider($setting);
        $config['provider'] = $provider;

        // Merge submitted fields over the stored ones. A field the request omits
        // entirely keeps its stored value; one that IS sent overwrites, even to
        // null — Laravel's ConvertEmptyStringsToNull turns a cleared input into
        // null before it reaches here, and an optional field (e.g. ZADX
        // `sender_id`, meant to be left blank to use the app's own default) must
        // be clearable back to empty, not stuck at whatever was saved once. Only
        // the secret is different: it is only replaced when a non-empty one is
        // sent, handled separately below.
        foreach (self::PUBLIC_FIELDS[$provider] as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = $data[$key];
            }
        }

        $secret = self::SECRETS[$provider];

        if (! empty($data[$secret])) {
            $config[$secret] = $data[$secret];
        }

        $enabled = (bool) $data['enabled'];

        if ($enabled && ! $this->isComplete($provider, $config)) {
            return response()->json([
                'message' => 'Provide '.implode(', ', self::REQUIRED[$provider]).' before enabling SMS.',
            ], 422);
        }

        $setting->channel = NotificationChannel::Sms->value;
        $setting->config = $config;
        $setting->is_active = $enabled;
        $setting->save();

        return response()->json(['data' => $this->payload($setting)]);
    }

    /** The current tenant's SMS channel row, not persisted until saved. */
    private function setting(): NotificationChannelSetting
    {
        return NotificationChannelSetting::query()
            ->firstOrNew(['channel' => NotificationChannel::Sms->value]);
    }

    /**
     * A stored row keeps its own provider. A brand-new row follows the
     * deployment's driver, so an academy onboarding today is offered the gateway
     * the platform actually sends through rather than the historical default.
     */
    private function provider(NotificationChannelSetting $setting): string
    {
        $stored = (string) (($setting->config ?? [])['provider'] ?? '');

        if (isset(self::REQUIRED[$stored])) {
            return $stored;
        }

        $driver = (string) config('sms.driver');

        return isset(self::REQUIRED[$driver]) ? $driver : 'zadx';
    }

    /** @param array<string,mixed> $config */
    private function isComplete(string $provider, array $config): bool
    {
        foreach (self::REQUIRED[$provider] as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Safe view of the settings — the secret is masked to a boolean so it never
     * leaves the server. The ZADX api_key is public (`pk_…`) and IS returned:
     * the teacher needs to see which app he wired up.
     *
     * @return array<string,mixed>
     */
    private function payload(NotificationChannelSetting $setting): array
    {
        $config = $setting->config ?? [];
        $provider = $this->provider($setting);

        $payload = [
            'provider' => $provider,
            'enabled' => (bool) $setting->is_active,
            'has_secret' => ! empty($config[self::SECRETS[$provider]]),
        ];

        foreach (self::PUBLIC_FIELDS[$provider] as $key) {
            $payload[$key] = $config[$key] ?? null;
        }

        $payload['base_url'] ??= (string) config("sms.{$provider}.base_url");

        // Kept for the SPA released before the ZADX move, which reads this key.
        $payload['has_password'] = $payload['has_secret'];

        return $payload;
    }
}
