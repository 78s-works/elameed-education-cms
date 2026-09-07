<?php

namespace App\Modules\Notifications\Services\Engine;

use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Resolvers\NotificationTemplateResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a catalog notification to a bare PHONE NUMBER — for the two cases where
 * the recipient is not a user row the engine can address:
 *
 *   1. A guardian. "Tell the parent too" is only half solved by the engine: a
 *      guardian linked as a parent user is a normal recipient, but most
 *      academies only ever record a `guardian_phone` on the student profile, and
 *      an absence is exactly what a parent must hear about.
 *   2. An OTP. The code is sent to an identifier before any session exists, and
 *      routing it through the catalog is what lets an academy reword it and
 *      have it read in Arabic.
 *
 * The same system/tenant template the in-app copy uses is rendered here, so a
 * teacher's wording override applies to these messages too. Delivery is
 * best-effort: a failure is logged, never thrown, so a bad number cannot roll
 * back the attendance record (or the OTP row) that triggered it.
 */
class TemplatedSmsNotifier
{
    public function __construct(
        private readonly NotificationTemplateResolver $resolver,
        private readonly TemplateInterpolator $interpolator,
        private readonly ChannelAvailability $availability,
        private readonly SmsSender $sms,
    ) {}

    /**
     * @param  list<string>  $phones
     * @param  array<string, mixed>  $variables
     * @return int  numbers actually texted
     */
    public function send(string $notificationKey, int $tenantId, array $phones, array $variables = []): int
    {
        $phones = array_values(array_unique(array_filter(array_map('trim', $phones))));

        if ($phones === [] || ! $this->availability->isAvailable($tenantId, NotificationChannel::Sms)) {
            return 0;
        }

        $text = $this->render($notificationKey, $tenantId, $variables);

        if ($text === null) {
            return 0;
        }

        $sent = 0;

        foreach ($phones as $phone) {
            try {
                $this->sms->send($phone, $text);
                $sent++;
            } catch (Throwable $e) {
                Log::warning('[notifications] templated sms failed', [
                    'key' => $notificationKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * The rendered SMS text for a notification, or null when the academy has no
     * usable copy for it.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $notificationKey, int $tenantId, array $variables = []): ?string
    {
        $type = NotificationType::query()->where('key', $notificationKey)->first();

        if ($type === null || $type->status !== NotificationTypeStatus::Ready) {
            return null;
        }

        $template = $this->resolver->resolveForTenant($type, $tenantId)[NotificationChannel::Sms->value] ?? null;

        if ($template === null) {
            return null;
        }

        $tenant = Tenant::query()->with('teacherProfile')->find($tenantId);
        $language = $tenant?->teacherProfile?->primary_locale ?: (string) config('tenancy.default_locale', 'ar');

        $translation = $this->resolver->pickTranslation($template, $language);

        if ($translation === null) {
            return null;
        }

        $vars = array_merge([
            'app_name' => (string) config('app.name', 'Elameed'),
            'tenant_name' => (string) ($tenant?->name ?? config('app.name', 'Elameed')),
            'now' => now()->toDateTimeString(),
        ], $variables);

        return trim(
            $this->interpolator->render($translation->title, $vars).' '.
            $this->interpolator->render($translation->body, $vars)
        );
    }
}
