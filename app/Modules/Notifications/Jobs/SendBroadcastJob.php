<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\NotificationBroadcast;
use App\Modules\Notifications\Services\Broadcasts\BroadcastService;
use App\Modules\Notifications\Support\RunsInTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one custom notification off the request. An SMS blast to a few
 * thousand students must never run inside the HTTP call that confirmed it.
 *
 * Addressed by uuid rather than by a serialized model so the row is re-read
 * fresh — the claim in `BroadcastService::send` is what makes a double
 * delivery impossible if the job is retried.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Queueable, RunsInTenantContext;

    public int $tries = 3;

    public function __construct(
        public string $broadcastUuid,
        public ?int $tenantId = null,
    ) {}

    public function handle(BroadcastService $broadcasts): void
    {
        $this->inTenantContext($this->tenantId, function () use ($broadcasts): void {
            $broadcast = NotificationBroadcast::query()
                ->where('uuid', $this->broadcastUuid)
                ->first();

            if ($broadcast === null) {
                return;
            }

            $broadcasts->send($broadcast);
        });
    }
}
