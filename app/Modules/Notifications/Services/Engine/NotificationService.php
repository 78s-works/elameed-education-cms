<?php

namespace App\Modules\Notifications\Services\Engine;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationTemplateTranslation;
use App\Modules\Notifications\Models\NotificationType;
use App\Support\Exceptions\DomainException;

/**
 * Template/type/translation CRUD shared by both management surfaces (doc 10 §9):
 * central admin authors system-scope rows; the teacher dashboard drives tenant
 * overrides through TenantNotificationOverrideService. This service holds the
 * scope-agnostic operations so both controllers stay thin.
 */
class NotificationService
{
    /**
     * Create or activate a SYSTEM template for (type, channel). System templates
     * are always forced active (doc 10 §3).
     */
    public function upsertSystemTemplate(NotificationType $type, NotificationChannel $channel, ?int $userId): NotificationTemplate
    {
        $template = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('channel', $channel->value)
            ->where('scope', TemplateScope::System->value)
            ->whereNull('tenant_id')
            ->first();

        if ($template !== null) {
            $template->update(['is_active' => true, 'edited_by' => $userId]);

            return $template;
        }

        return NotificationTemplate::create([
            'notification_type_id' => $type->getKey(),
            'scope' => TemplateScope::System->value,
            'tenant_id' => null,
            'channel' => $channel->value,
            'is_active' => true,
            'created_by' => $userId,
            'edited_by' => $userId,
        ]);
    }

    /**
     * Create or update the translation copy for a template + language.
     */
    public function upsertTranslation(
        NotificationTemplate $template,
        string $language,
        string $title,
        string $body,
        ?int $userId,
    ): NotificationTemplateTranslation {
        $translation = $template->translations()->firstWhere('language', $language);

        if ($translation !== null) {
            $translation->update([
                'title' => $title,
                'body' => $body,
                'edited_by' => $userId,
            ]);

            return $translation;
        }

        return $template->translations()->create([
            'language' => $language,
            'title' => $title,
            'body' => $body,
            'created_by' => $userId,
            'edited_by' => $userId,
        ]);
    }

    /**
     * Set a template's activation flag. Refuses to deactivate a system template —
     * system rows are forced active (doc 10 §3); use a tenant override to disable
     * a channel for one tenant.
     */
    public function setActive(NotificationTemplate $template, bool $active, ?int $userId): NotificationTemplate
    {
        if ($template->scope === TemplateScope::System && ! $active) {
            throw new DomainException(
                'notification_system_template_locked',
                'System templates cannot be deactivated.',
            );
        }

        $template->update(['is_active' => $active, 'edited_by' => $userId]);

        return $template;
    }

    /**
     * Effective template per channel for a tenant, with its translations eager
     * loaded, for the teacher catalog view (doc 10 §9.2). Uses the resolver so
     * disabled overrides are correctly omitted.
     *
     * @return array<string, NotificationTemplate>
     */
    public function effectiveTemplatesForTenant(NotificationTemplateResolver $resolver, NotificationType $type, int $tenantId): array
    {
        $resolved = $resolver->resolveForTenant($type, $tenantId);

        foreach ($resolved as $template) {
            $template->loadMissing('translations');
        }

        return $resolved;
    }

    public function assertReady(NotificationType $type): void
    {
        if ($type->status !== NotificationTypeStatus::Ready) {
            throw new DomainException(
                'notification_type_not_ready',
                'This notification type is not ready.',
            );
        }
    }
}
