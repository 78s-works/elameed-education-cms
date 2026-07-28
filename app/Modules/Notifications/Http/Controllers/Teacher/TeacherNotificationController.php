<?php

namespace App\Modules\Notifications\Http\Controllers\Teacher;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Http\Requests\TeacherOverrideChannelRequest;
use App\Modules\Notifications\Http\Requests\UpsertTranslationRequest;
use App\Modules\Notifications\Http\Resources\NotificationTemplateResource;
use App\Modules\Notifications\Http\Resources\NotificationTranslationResource;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Engine\NotificationService;
use App\Modules\Notifications\Services\Engine\TenantNotificationOverrideService;
use App\Modules\Notifications\Services\Resolvers\NotificationTemplateResolver;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /teacher/notifications (doc 10 §9.2) — the tenant override surface. A teacher
 * customizes copy/activation of `ready` system notifications for their academy;
 * the first edit materializes a copy-on-write tenant template. Teachers cannot
 * author types or templates from scratch.
 */
class TeacherNotificationController
{
    public function __construct(
        private readonly NotificationTemplateResolver $resolver,
        private readonly NotificationService $service,
        private readonly TenantNotificationOverrideService $overrides,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Catalog of ready types with the effective template per channel. */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantOrFail()->getKey();

        $types = NotificationType::query()
            ->where('status', NotificationTypeStatus::Ready->value)
            ->orderBy('module')->orderBy('key')
            ->get();

        $data = $types->map(fn (NotificationType $type) => $this->present($type, $tenantId));

        return response()->json(['data' => $data]);
    }

    public function show(NotificationType $type): JsonResponse
    {
        $this->assertReadyVisible($type);
        $tenantId = $this->tenantContext->tenantOrFail()->getKey();

        return response()->json(['data' => $this->present($type, $tenantId)]);
    }

    /** Materialize a tenant override and set its activation (hard-disable a channel). */
    public function overrideChannel(TeacherOverrideChannelRequest $request, NotificationType $type): JsonResponse
    {
        $this->assertReadyVisible($type);
        $tenantId = $this->tenantContext->tenantOrFail()->getKey();
        $userId = $request->user()?->getKey();

        $data = $request->validated();
        $channel = NotificationChannel::from($data['channel']);

        $template = $this->overrides->editableTemplate($type, $channel, $tenantId, $userId);
        $template = $this->service->setActive($template, (bool) $data['is_active'], $userId);

        app(AuditLogger::class)->log(
            'notification_override.channel_set',
            ['key' => $type->key, 'channel' => $channel->value, 'is_active' => (bool) $data['is_active']],
            null,
            'notification_template',
            $template->id,
        );

        return (new NotificationTemplateResource($template->load('translations')))->response();
    }

    /** Edit tenant copy for one language; materializes the override first. */
    public function upsertTranslation(UpsertTranslationRequest $request, NotificationType $type, string $channel): JsonResponse
    {
        $this->assertReadyVisible($type);
        $tenantId = $this->tenantContext->tenantOrFail()->getKey();
        $userId = $request->user()?->getKey();

        $channelEnum = NotificationChannel::tryFrom($channel);
        abort_if($channelEnum === null, 404);

        $template = $this->overrides->editableTemplate($type, $channelEnum, $tenantId, $userId);
        $data = $request->validated();

        $translation = $this->service->upsertTranslation(
            $template,
            $data['language'],
            $data['title'],
            $data['body'],
            $userId,
        );

        app(AuditLogger::class)->log(
            'notification_override.translation_upserted',
            ['key' => $type->key, 'channel' => $channelEnum->value, 'language' => $data['language']],
            null,
            'notification_template',
            $template->id,
        );

        return (new NotificationTranslationResource($translation))->response();
    }

    /** Reset a channel to the system default by deleting the tenant override. */
    public function reset(NotificationType $type, string $channel): Response
    {
        $this->assertReadyVisible($type);
        $tenantId = $this->tenantContext->tenantOrFail()->getKey();

        $channelEnum = NotificationChannel::tryFrom($channel);
        abort_if($channelEnum === null, 404);

        $this->overrides->discardOverride($type, $channelEnum, $tenantId);

        app(AuditLogger::class)->log(
            'notification_override.reset',
            ['key' => $type->key, 'channel' => $channelEnum->value],
            null,
            'notification_type',
            $type->id,
        );

        return response()->noContent();
    }

    /**
     * Effective template per channel for this tenant.
     *
     * @return array<string, mixed>
     */
    private function present(NotificationType $type, int $tenantId): array
    {
        $effective = $this->service->effectiveTemplatesForTenant($this->resolver, $type, $tenantId);

        $channels = [];
        foreach ($effective as $channelValue => $template) {
            $channels[$channelValue] = (new NotificationTemplateResource($template))->toArray(request());
        }

        return [
            'key' => $type->key,
            'module' => $type->module->value,
            'severity' => $type->severity->value,
            'channels' => $channels,
        ];
    }

    /** Teacher screens only ever see `ready` types (doc 10 §8). */
    private function assertReadyVisible(NotificationType $type): void
    {
        abort_if($type->status !== NotificationTypeStatus::Ready, 404);
    }
}
