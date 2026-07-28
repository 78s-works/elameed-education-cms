<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Http\Requests\StoreSystemTemplateRequest;
use App\Modules\Notifications\Http\Resources\NotificationTemplateResource;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Engine\NotificationService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /admin/notifications/types/{type}/templates (doc 10 §9.1). Create/activate a
 * SYSTEM template for a (type, channel); templates hold no text — add copy via
 * translations. System templates are forced active.
 */
class TemplateController
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(NotificationType $type): AnonymousResourceCollection
    {
        $templates = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('scope', TemplateScope::System->value)
            ->whereNull('tenant_id')
            ->with('translations')
            ->get();

        return NotificationTemplateResource::collection($templates);
    }

    public function store(StoreSystemTemplateRequest $request, NotificationType $type): JsonResponse
    {
        $channel = NotificationChannel::from($request->validated()['channel']);

        $template = $this->service->upsertSystemTemplate($type, $channel, $request->user()?->getKey());

        app(AuditLogger::class)->log(
            'notification_template.system_upserted',
            ['key' => $type->key, 'channel' => $channel->value],
            null,
            'notification_template',
            $template->id,
        );

        return (new NotificationTemplateResource($template->load('translations')))
            ->response()
            ->setStatusCode(201);
    }
}
