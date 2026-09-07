<?php

namespace App\Modules\Notifications\Http\Controllers\Teacher;

use App\Modules\Notifications\Enums\BroadcastStatus;
use App\Modules\Notifications\Http\Requests\BroadcastRequest;
use App\Modules\Notifications\Http\Resources\NotificationBroadcastResource;
use App\Modules\Notifications\Jobs\SendBroadcastJob;
use App\Modules\Notifications\Models\NotificationBroadcast;
use App\Modules\Notifications\Services\Broadcasts\BroadcastService;
use App\Modules\Notifications\Services\Engine\ChannelAvailability;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /teacher/notifications/custom — an academy writes its own message.
 *
 * The sender picks an audience (everyone, one lesson, one package, one grade,
 * one center, hand-picked students, or the assistants), the channels, and either
 * sends now or schedules it. `preview` is the cost gate: it answers how many
 * people this reaches and what the SMS half costs, and the UI shows that before
 * the confirm button does anything.
 *
 * Behind `can:settings.notifications.send`, which assistants do not hold unless
 * the teacher granted it.
 */
class BroadcastController
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly TenantContext $tenants,
        private readonly ChannelAvailability $availability,
    ) {}

    /**
     * Which channels this academy can actually send on right now. The composer
     * reads it to grey out SMS until the teacher stores WE credentials, rather
     * than letting him compose a blast that would silently go nowhere.
     */
    public function channels(): JsonResponse
    {
        return response()->json([
            'data' => [
                'available' => $this->availability->availableFor($this->tenants->tenantOrFail()->getKey()),
            ],
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = NotificationBroadcast::query()
            ->forTenant($this->tenants->tenantOrFail()->getKey())
            ->with('author')
            ->latest('id')
            ->paginate(20);

        return NotificationBroadcastResource::collection($items);
    }

    /** Reach + SMS cost for a payload the sender has not committed to yet. */
    public function preview(BroadcastRequest $request): JsonResponse
    {
        $preview = $this->broadcasts->preview(
            $request->validated(),
            $this->tenants->tenantOrFail()->getKey(),
        );

        return response()->json(['data' => $preview]);
    }

    public function store(BroadcastRequest $request): JsonResponse
    {
        $tenantId = $this->tenants->tenantOrFail()->getKey();

        $broadcast = $this->broadcasts->create(
            $request->validated(),
            $tenantId,
            $request->user()?->getKey(),
        );

        // No schedule = send now, but still off the request: a blast to a few
        // thousand students must not run inside the HTTP call.
        if ($broadcast->scheduled_at === null) {
            SendBroadcastJob::dispatch($broadcast->uuid, $tenantId);
        }

        return response()->json(
            ['data' => (new NotificationBroadcastResource($broadcast))->toArray($request)],
            201,
        );
    }

    public function show(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        $this->assertOwnedByTenant($broadcast);

        return response()->json([
            'data' => (new NotificationBroadcastResource($broadcast->load('author')))->toArray($request),
        ]);
    }

    /** Call off a message that has not gone out yet. */
    public function cancel(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        $this->assertOwnedByTenant($broadcast);

        abort_unless(
            $broadcast->status->isCancelable(),
            422,
            'Only a scheduled notification can be canceled.',
        );

        $broadcast->update(['status' => BroadcastStatus::Canceled->value]);

        return response()->json([
            'data' => (new NotificationBroadcastResource($broadcast))->toArray($request),
        ]);
    }

    /**
     * NotificationBroadcast is not tenant-scoped by a global scope (it also
     * holds platform rows), so ownership is checked here explicitly.
     */
    private function assertOwnedByTenant(NotificationBroadcast $broadcast): void
    {
        abort_unless(
            $broadcast->tenant_id === $this->tenants->tenantOrFail()->getKey(),
            404,
        );
    }
}
