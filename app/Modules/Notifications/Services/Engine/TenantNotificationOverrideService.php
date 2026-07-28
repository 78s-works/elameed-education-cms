<?php

namespace App\Modules\Notifications\Services\Engine;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationType;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Copy-on-write for tenant template overrides (doc 10 §9). A teacher never
 * authors a template from scratch — the first edit materializes a tenant copy of
 * the system template (duplicating its translations). The system row is never
 * mutated.
 */
class TenantNotificationOverrideService
{
    /**
     * Return the tenant-editable template for (type, channel), creating it lazily
     * from the system template on first edit.
     *
     * @throws DomainException if the type is not `ready`, or no system template
     *                         exists to derive from.
     */
    public function editableTemplate(
        NotificationType $type,
        NotificationChannel $channel,
        int $tenantId,
        ?int $userId,
    ): NotificationTemplate {
        if ($type->status !== NotificationTypeStatus::Ready) {
            throw new DomainException(
                'notification_type_not_ready',
                'This notification is not available for customization.',
            );
        }

        $existing = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('channel', $channel->value)
            ->where('scope', TemplateScope::Tenant->value)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($type, $channel, $tenantId, $userId): NotificationTemplate {
            // Re-check inside the transaction (guards a concurrent first edit).
            $existing = NotificationTemplate::query()
                ->where('notification_type_id', $type->getKey())
                ->where('channel', $channel->value)
                ->where('scope', TemplateScope::Tenant->value)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $system = NotificationTemplate::query()
                ->where('notification_type_id', $type->getKey())
                ->where('channel', $channel->value)
                ->where('scope', TemplateScope::System->value)
                ->whereNull('tenant_id')
                ->with('translations')
                ->first();

            if ($system === null) {
                throw new DomainException(
                    'notification_system_template_missing',
                    'There is no base template for this channel to customize.',
                );
            }

            $override = NotificationTemplate::create([
                'notification_type_id' => $type->getKey(),
                'scope' => TemplateScope::Tenant->value,
                'tenant_id' => $tenantId,
                'channel' => $channel->value,
                // Mirror the system row so materializing an override does not
                // silently disable the channel; the teacher toggles it explicitly.
                'is_active' => $system->is_active,
                'created_by' => $userId,
                'edited_by' => $userId,
            ]);

            foreach ($system->translations as $translation) {
                $override->translations()->create([
                    'language' => $translation->language,
                    'title' => $translation->title,
                    'body' => $translation->body,
                    'created_by' => $userId,
                    'edited_by' => $userId,
                ]);
            }

            return $override;
        });
    }

    /**
     * Reset a tenant's channel to the system default by deleting the override.
     * No-op if no override exists.
     */
    public function discardOverride(NotificationType $type, NotificationChannel $channel, int $tenantId): void
    {
        NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('channel', $channel->value)
            ->where('scope', TemplateScope::Tenant->value)
            ->where('tenant_id', $tenantId)
            ->delete();
    }
}
