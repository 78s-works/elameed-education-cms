<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Http\Requests\AdminBroadcastRequest;
use App\Modules\Notifications\Http\Resources\NotificationBroadcastResource;
use App\Modules\Notifications\Jobs\SendBroadcastJob;
use App\Modules\Notifications\Models\NotificationBroadcast;
use App\Modules\Notifications\Services\Broadcasts\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /admin/notifications/custom — the central admin writes to every teacher on the
 * platform, in-app and by email.
 *
 * These rows carry `tenant_id = null`: the message belongs to the platform, not
 * to any academy, which is also why the teacher surface can never see or cancel
 * one.
 */
class BroadcastController
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function index(): AnonymousResourceCollection
    {
        $items = NotificationBroadcast::query()
            ->forTenant(null)
            ->with('author')
            ->latest('id')
            ->paginate(20);

        return NotificationBroadcastResource::collection($items);
    }

    public function preview(AdminBroadcastRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->broadcasts->preview($request->validated(), null),
        ]);
    }

    public function store(AdminBroadcastRequest $request): JsonResponse
    {
        $broadcast = $this->broadcasts->create(
            $request->validated(),
            null,
            $request->user()?->getKey(),
        );

        if ($broadcast->scheduled_at === null) {
            SendBroadcastJob::dispatch($broadcast->uuid, null);
        }

        return response()->json(
            ['data' => (new NotificationBroadcastResource($broadcast))->toArray($request)],
            201,
        );
    }

    public function show(Request $request, NotificationBroadcast $broadcast): JsonResponse
    {
        abort_unless($broadcast->tenant_id === null, 404);

        return response()->json([
            'data' => (new NotificationBroadcastResource($broadcast->load('author')))->toArray($request),
        ]);
    }
}
