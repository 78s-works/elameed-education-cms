<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Http\Resources\NotificationEventResource;
use App\Modules\Notifications\Models\NotificationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /admin/notifications/events (doc 10 §9.1) — read-only auditor of dispatched
 * events across the platform. Central admin sees every tenant's events (this is
 * the cross-tenant surface; these tables are not RLS-forced by design).
 */
class EventController
{
    /**
     * Delivery state per event. A message row is written only on a successful
     * hand-off to the channel, with a `sent` log; anything without that log is
     * still queued, and a rejected recipient lands in `notification_failures`.
     * Counting all three separately is what makes the console's columns add up.
     *
     * @return array<int|string, callable|string>
     */
    private function deliveryCounts(): array
    {
        return [
            'notifications as delivered_count' => fn ($q) => $q->whereHas('logs', fn ($l) => $l->where('status', 'sent')),
            'notifications as pending_count' => fn ($q) => $q->whereDoesntHave('logs', fn ($l) => $l->where('status', 'sent')),
            'failures',
        ];
    }

    /**
     * The dispatch log is read to answer a question — "did this academy's OTPs
     * go out on Tuesday?" — so it filters by academy, by date range, and by
     * free text over the type key, the academy name, and the entity id.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = NotificationEvent::query()
            ->with(['type', 'tenant:id,uuid,name'])
            ->withCount($this->deliveryCounts())
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->query('tenant'), function ($q, $tenant): void {
                is_numeric($tenant)
                    ? $q->where('tenant_id', (int) $tenant)
                    : $q->whereHas('tenant', fn ($t) => $t->where('uuid', $tenant));
            })
            ->when($request->query('q'), function ($q, $term): void {
                $like = '%'.$term.'%';
                $q->where(function ($inner) use ($like, $term): void {
                    $inner->whereHas('type', fn ($t) => $t->where('key', 'like', $like))
                        ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', $like))
                        ->orWhere('entity_id', $term);
                });
            })
            ->latest('id')
            ->paginate(min(100, max(10, (int) $request->query('per_page', 30))))
            ->withQueryString();

        return NotificationEventResource::collection($events);
    }

    public function show(NotificationEvent $event): NotificationEventResource
    {
        $event->load(['type', 'tenant:id,uuid,name'])->loadCount($this->deliveryCounts());

        return new NotificationEventResource($event);
    }

    /**
     * Every failed recipient of one event, with the reason the channel gave.
     * A failure count is only actionable if the admin can open it and see who
     * did not receive what, and why.
     */
    public function failures(NotificationEvent $event): JsonResponse
    {
        $failures = $event->failures()
            ->with('user:id,uuid,name,phone,email')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $failures->map(fn ($failure) => [
            'id' => $failure->getKey(),
            'channel' => $failure->channel?->value,
            'error_message' => $failure->error_message,
            'created_at' => $failure->created_at?->toIso8601String(),
            'recipient' => $failure->user === null ? null : [
                'uuid' => $failure->user->uuid,
                'name' => $failure->user->name,
                'phone' => $failure->user->phone,
                'email' => $failure->user->email,
            ],
        ])->values()]);
    }
}
