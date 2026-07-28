<?php

namespace App\Modules\Notifications\Services\Engine;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationFailure;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Factories\ChannelFactory;
use App\Modules\Notifications\Services\Resolvers\NotificationTemplateResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Notification engine — the single public entry point (doc 10 §4, §12). Turns one
 * business event into rendered, per-recipient, per-channel messages, respecting
 * multi-tenancy, two-tier templating, localization, opt-out preferences, and an
 * immutable audit trail.
 *
 * This is a library/service, NOT an HTTP endpoint. Callers fire:
 *   app(NotificationEngineService::class)->dispatch(notificationKey: ..., ...)
 *
 * Runtime assumption: dispatch runs inside a resolved-tenant context (the Postgres
 * `app.tenant_id` GUC is set), matching every request path that fires it. Deliveries
 * and channel settings are RLS-forced, so a queued caller must resolve the tenant
 * (set the GUC) before dispatching.
 */
class NotificationEngineService
{
    public function __construct(
        private readonly NotificationTemplateResolver $resolver,
        private readonly ChannelFactory $channelFactory,
        private readonly TemplateInterpolator $interpolator,
    ) {}

    /**
     * @param  array<int, int>  $recipientUserIds
     * @param  array<string, mixed>  $renderVariables  Not persisted (doc 10 §11).
     * @param  array<string, mixed>  $auditPayload     Non-sensitive; persisted on the event.
     * @param  array<string, mixed>  $options          e.g. ['channel_var_blacklist' => ['sms' => ['otp']]]
     * @return array<string, mixed>  Summary (doc 10 §12 output).
     */
    public function dispatch(
        string $notificationKey,
        int $tenantId,
        array $recipientUserIds,
        array $renderVariables = [],
        ?int $triggeredByUserId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $auditPayload = [],
        array $options = [],
    ): array {
        $summary = [
            'notification_type_found' => false,
            'notification_event_id' => null,
            'channels' => [],
            'totals' => ['attempted' => 0, 'sent' => 0, 'failed' => 0],
        ];

        // 1. Resolve type by key.
        $type = NotificationType::query()->where('key', $notificationKey)->first();
        if ($type === null) {
            Log::warning('[notifications] unknown notification key', ['key' => $notificationKey]);

            return $summary;
        }
        $summary['notification_type_found'] = true;

        // 2. Lifecycle gate — nothing recorded for non-ready types.
        if ($type->status !== NotificationTypeStatus::Ready) {
            Log::info('[notifications] type not ready, skipping', [
                'key' => $notificationKey,
                'status' => $type->status->value,
            ]);

            return $summary;
        }

        // 3. Record the immutable event (audit payload only).
        $event = new NotificationEvent([
            'notification_type_id' => $type->getKey(),
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $auditPayload ?: null,
            'triggered_by' => $triggeredByUserId,
        ]);
        $event->save();
        $summary['notification_event_id'] = $event->getKey();

        // 4. Resolve active templates per channel.
        $templates = $this->resolver->resolveForTenant($type, $tenantId);
        if ($templates === []) {
            return $summary;
        }

        // 5. Load recipients.
        $recipients = User::query()->whereIn('id', array_values(array_unique($recipientUserIds)))->get();
        if ($recipients->isEmpty()) {
            return $summary;
        }

        // 6. Built-in variables + resolved language for the tenant.
        $tenant = Tenant::query()->with('teacherProfile')->find($tenantId);
        $builtIns = $this->builtInVariables($tenant);
        $language = $this->tenantLanguage($tenant);

        $blacklist = $options['channel_var_blacklist'] ?? [];

        // 7. Per channel.
        foreach ($templates as $channelValue => $template) {
            $channel = NotificationChannel::from($channelValue);

            // a. Tenant channel kill-switch.
            if ($this->channelDisabledForTenant($tenantId, $channel)) {
                continue;
            }

            // b. Merge vars + strip blacklisted keys for this channel.
            $vars = array_merge($builtIns, $renderVariables);
            foreach (($blacklist[$channelValue] ?? []) as $key) {
                unset($vars[$key]);
            }

            // c. Channel formatting hook.
            $vars = $this->applyChannelFormatting($channel, $vars);

            // d. Render title + body.
            $translation = $this->resolver->pickTranslation($template, $language);
            if ($translation === null) {
                continue; // no copy → no message for this channel
            }
            $title = $this->interpolator->render($translation->title, $vars);
            $body = $this->interpolator->render($translation->body, $vars);

            // e. Pick dispatcher.
            $dispatcher = $this->channelFactory->make($channel);
            if ($dispatcher === null) {
                Log::warning('[notifications] no dispatcher for channel', ['channel' => $channelValue]);

                continue;
            }

            $counters = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

            // f. Per recipient.
            foreach ($recipients as $user) {
                if ($this->recipientOptedOut($user->getKey(), $type->getKey(), $channel)) {
                    continue;
                }

                $counters['attempted']++;
                $result = $dispatcher->send($event, $user, $title, $body, ['language' => $language]);

                if ($result->success) {
                    $counters['sent']++;
                } else {
                    $counters['failed']++;
                    NotificationFailure::create([
                        'notification_event_id' => $event->getKey(),
                        'user_id' => $user->getKey(),
                        'channel' => $channel->value,
                        'error_message' => $result->error ?? 'Unknown error',
                    ]);
                }
            }

            $summary['channels'][$channelValue] = $counters;
            $summary['totals']['attempted'] += $counters['attempted'];
            $summary['totals']['sent'] += $counters['sent'];
            $summary['totals']['failed'] += $counters['failed'];
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private function builtInVariables(?Tenant $tenant): array
    {
        return [
            'app_name' => (string) config('app.name', 'Elameed'),
            'app_url' => (string) config('app.url', ''),
            'tenant_name' => (string) ($tenant?->name ?? config('app.name', 'Elameed')),
            'now' => now()->toDateTimeString(),
        ];
    }

    private function tenantLanguage(?Tenant $tenant): string
    {
        $primary = $tenant?->teacherProfile?->primary_locale;

        return $primary ?: (string) config('tenancy.default_locale', 'ar');
    }

    private function channelDisabledForTenant(int $tenantId, NotificationChannel $channel): bool
    {
        $setting = NotificationChannelSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel->value)
            ->first();

        return $setting !== null && ! $setting->is_active;
    }

    private function recipientOptedOut(int $userId, int $typeId, NotificationChannel $channel): bool
    {
        $pref = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('notification_type_id', $typeId)
            ->where('channel', $channel->value)
            ->first();

        return $pref !== null && ! $pref->is_enabled;
    }

    /**
     * Channel-specific variable shaping (doc 10 §4 step 7c). Email wraps app_url
     * in an anchor; sms/database keep plain text.
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    private function applyChannelFormatting(NotificationChannel $channel, array $vars): array
    {
        if ($channel === NotificationChannel::Email && ! empty($vars['app_url'])) {
            $url = $vars['app_url'];
            $vars['app_url'] = sprintf('<a href="%s">%s</a>', $url, $url);
        }

        return $vars;
    }
}
