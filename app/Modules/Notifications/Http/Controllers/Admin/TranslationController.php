<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Http\Requests\UpsertTranslationRequest;
use App\Modules\Notifications\Http\Resources\NotificationTranslationResource;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Engine\NotificationService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * /admin/notifications/types/{type}/templates/{channel}/translations (doc 10
 * §9.1). Where the actual system-scope copy (title/body per language) is written.
 */
class TranslationController
{
    public function __construct(private readonly NotificationService $service) {}

    public function upsert(UpsertTranslationRequest $request, NotificationType $type, string $channel): JsonResponse
    {
        $template = $this->systemTemplate($type, $channel);
        $data = $request->validated();

        $translation = $this->service->upsertTranslation(
            $template,
            $data['language'],
            $data['title'],
            $data['body'],
            $request->user()?->getKey(),
        );

        app(AuditLogger::class)->log(
            'notification_translation.upserted',
            ['key' => $type->key, 'channel' => $channel, 'language' => $data['language']],
            null,
            'notification_template',
            $template->id,
        );

        return (new NotificationTranslationResource($translation))->response()->setStatusCode(200);
    }

    public function destroy(NotificationType $type, string $channel, string $language): Response
    {
        $template = $this->systemTemplate($type, $channel);
        $template->translations()->where('language', $language)->delete();

        app(AuditLogger::class)->log(
            'notification_translation.deleted',
            ['key' => $type->key, 'channel' => $channel, 'language' => $language],
            null,
            'notification_template',
            $template->id,
        );

        return response()->noContent();
    }

    private function systemTemplate(NotificationType $type, string $channel): NotificationTemplate
    {
        $channelEnum = NotificationChannel::tryFrom($channel);
        abort_if($channelEnum === null, 404);

        $template = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('channel', $channelEnum->value)
            ->where('scope', TemplateScope::System->value)
            ->whereNull('tenant_id')
            ->first();

        abort_if($template === null, 404, 'System template not found for this channel.');

        return $template;
    }
}
