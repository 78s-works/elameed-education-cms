<?php

namespace App\Modules\Notifications\Services\Resolvers;

use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationTemplateTranslation;
use App\Modules\Notifications\Models\NotificationType;

/**
 * Resolves which template and which translation win for a (type, tenant),
 * following doc 10 §6.
 *
 * Channel precedence, per channel:
 *   - tenant override exists & is_active = false → channel DISABLED (no fallback)
 *   - tenant override exists & is_active = true  → use the tenant override
 *   - no tenant override                         → use the active system template
 *
 * Language precedence: requested → en → first available.
 */
class NotificationTemplateResolver
{
    /**
     * Effective template per channel for this tenant. Channels that resolve to a
     * disabled override or have no usable template are omitted.
     *
     * @return array<string, NotificationTemplate>  keyed by channel value
     */
    public function resolveForTenant(NotificationType $type, int $tenantId): array
    {
        /** @var \Illuminate\Support\Collection<int, NotificationTemplate> $rows */
        $rows = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where(function ($q) use ($tenantId): void {
                $q->where(function ($sys): void {
                    $sys->where('scope', TemplateScope::System->value)
                        ->whereNull('tenant_id')
                        ->where('is_active', true);
                })->orWhere(function ($ten) use ($tenantId): void {
                    $ten->where('scope', TemplateScope::Tenant->value)
                        ->where('tenant_id', $tenantId);
                });
            })
            ->get();

        $byChannel = [];

        foreach ($rows as $row) {
            $channel = $row->channel->value;
            $byChannel[$channel][$row->scope->value] = $row;
        }

        $resolved = [];

        foreach ($byChannel as $channel => $scoped) {
            $override = $scoped[TemplateScope::Tenant->value] ?? null;
            $system = $scoped[TemplateScope::System->value] ?? null;

            if ($override !== null) {
                if (! $override->is_active) {
                    continue; // hard disable, no fallback to system
                }
                $resolved[$channel] = $override;

                continue;
            }

            if ($system !== null) {
                $resolved[$channel] = $system; // already filtered to is_active = true
            }
        }

        return $resolved;
    }

    /**
     * Pick a translation: exact requested language, else `en`, else the first
     * available. Null if the template has no translations.
     */
    public function pickTranslation(NotificationTemplate $template, string $language): ?NotificationTemplateTranslation
    {
        $translations = $template->relationLoaded('translations')
            ? $template->translations
            : $template->translations()->get();

        $exact = $translations->firstWhere('language', $language);
        if ($exact !== null) {
            return $exact;
        }

        if ($language !== 'en') {
            $english = $translations->firstWhere('language', 'en');
            if ($english !== null) {
                return $english;
            }
        }

        return $translations->first();
    }
}
