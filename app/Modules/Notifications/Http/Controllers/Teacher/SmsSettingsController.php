<?php

namespace App\Modules\Notifications\Http\Controllers\Teacher;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Http\Requests\UpdateSmsSettingsRequest;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /teacher/sms-settings — a teacher manages his own academy's WE
 * Business SMS (Connekio) credentials. This is the "each tenant adds his own
 * data" surface: the platform holds no aggregator account, so SMS only works
 * for a tenant once its teacher fills this in and enables it.
 *
 * Stored on the current tenant's `notification_channel_settings` row for the
 * `sms` channel (BelongsToTenant scopes + auto-fills tenant_id; `config` is
 * encrypted at rest). The password is write-only — never returned.
 */
class SmsSettingsController
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->setting())]);
    }

    public function update(UpdateSmsSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $setting = $this->setting();
        $config = $setting->config ?? [];

        // Merge submitted fields over the stored ones; a password is only
        // replaced when a non-empty one is sent, so the teacher can toggle
        // `enabled` or edit the sender without re-typing the secret.
        $config['provider'] = 'connekio';
        foreach (['sender', 'username', 'account_id', 'base_url'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $config[$key] = $data[$key];
            }
        }
        if (! empty($data['password'])) {
            $config['password'] = $data['password'];
        }

        $enabled = (bool) $data['enabled'];

        if ($enabled && ! $this->isComplete($config)) {
            return response()->json([
                'message' => 'Provide sender, username, password and account_id before enabling SMS.',
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

    /** @param array<string,mixed> $config */
    private function isComplete(array $config): bool
    {
        foreach (['sender', 'username', 'password', 'account_id'] as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Safe view of the settings — the password is masked to a boolean so the
     * secret never leaves the server.
     *
     * @return array{enabled: bool, sender: ?string, username: ?string, account_id: ?string, base_url: ?string, has_password: bool}
     */
    private function payload(NotificationChannelSetting $setting): array
    {
        $config = $setting->config ?? [];

        return [
            'enabled' => (bool) $setting->is_active,
            'sender' => $config['sender'] ?? null,
            'username' => $config['username'] ?? null,
            'account_id' => $config['account_id'] ?? null,
            'base_url' => $config['base_url'] ?? config('sms.connekio.base_url'),
            'has_password' => ! empty($config['password']),
        ];
    }
}
