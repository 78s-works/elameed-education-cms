<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Http\Resources\NotificationEventResource;
use App\Modules\Notifications\Models\NotificationEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /admin/notifications/events (doc 10 §9.1) — read-only auditor of dispatched
 * events across the platform. Central admin sees every tenant's events (this is
 * the cross-tenant surface; these tables are not RLS-forced by design).
 */
class EventController
{
    public function index(): AnonymousResourceCollection
    {
        $events = NotificationEvent::query()
            ->with('type')
            ->withCount(['notifications', 'failures'])
            ->latest('id')
            ->paginate(30);

        return NotificationEventResource::collection($events);
    }

    public function show(NotificationEvent $event): NotificationEventResource
    {
        $event->load('type')->loadCount(['notifications', 'failures']);

        return new NotificationEventResource($event);
    }
}
