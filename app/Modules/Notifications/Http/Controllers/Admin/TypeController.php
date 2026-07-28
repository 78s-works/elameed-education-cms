<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Http\Requests\StoreNotificationTypeRequest;
use App\Modules\Notifications\Http\Requests\UpdateNotificationTypeRequest;
use App\Modules\Notifications\Http\Resources\NotificationTypeResource;
use App\Modules\Notifications\Models\NotificationType;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /admin/notifications/types (doc 10 §9.1) — the notification type catalog. The
 * only place types are authored. Central host + platform admin only; global (not
 * tenant-scoped). `key` is the identity; new types default to `draft` and go live
 * when flipped to `ready`.
 */
class TypeController
{
    public function index(): AnonymousResourceCollection
    {
        return NotificationTypeResource::collection(
            NotificationType::query()->orderBy('module')->orderBy('key')->get()
        );
    }

    public function store(StoreNotificationTypeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $type = NotificationType::create([
            'key' => $data['key'],
            'module' => $data['module'],
            'severity' => $data['severity'] ?? 'info',
            'is_system' => true,
            'status' => $data['status'] ?? NotificationTypeStatus::Draft->value,
        ]);

        app(AuditLogger::class)->log('notification_type.created', ['key' => $type->key], null, 'notification_type', $type->id);

        return (new NotificationTypeResource($type))->response()->setStatusCode(201);
    }

    public function show(NotificationType $type): NotificationTypeResource
    {
        return new NotificationTypeResource($type->load('templates.translations'));
    }

    public function update(UpdateNotificationTypeRequest $request, NotificationType $type): NotificationTypeResource
    {
        $type->update($request->validated());

        app(AuditLogger::class)->log('notification_type.updated', ['key' => $type->key, 'changes' => $request->validated()], null, 'notification_type', $type->id);

        return new NotificationTypeResource($type->refresh());
    }

    public function destroy(NotificationType $type): Response
    {
        $key = $type->key;
        $type->delete();

        app(AuditLogger::class)->log('notification_type.deleted', ['key' => $key], null, 'notification_type', null);

        return response()->noContent();
    }
}
