<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Jobs\SendBroadcastJob;
use App\Modules\Notifications\Models\NotificationBroadcast;
use Illuminate\Console\Command;

/**
 * Releases custom notifications whose scheduled time has arrived. Runs every
 * minute from the scheduler; the actual delivery happens in SendBroadcastJob so
 * a large blast never blocks the scheduler tick.
 */
class SendScheduledBroadcastsCommand extends Command
{
    protected $signature = 'notifications:send-scheduled {--limit=100 : Maximum broadcasts to release in one tick}';

    protected $description = 'Queue custom notifications whose scheduled time has passed';

    public function handle(): int
    {
        $due = NotificationBroadcast::query()
            ->due()
            ->orderBy('scheduled_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $broadcast) {
            SendBroadcastJob::dispatch($broadcast->uuid, $broadcast->tenant_id);
        }

        $this->info(sprintf('Queued %d scheduled notification(s).', $due->count()));

        return self::SUCCESS;
    }
}
