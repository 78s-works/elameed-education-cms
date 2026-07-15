<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Enums\MediaVersionState;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Media\Services\RemoteVideoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kicks off async processing on the Media Host for one version. Retries transient
 * failures; a terminal failure marks the version `failed` so the client can
 * trigger a safe retry. `attempt` feeds the processing idempotency key so a queue
 * retry doesn't create a duplicate host job, but a deliberate retry does.
 */
class StartRemoteProcessingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $versionId, public int $attempt = 1) {}

    public function handle(RemoteVideoService $service): void
    {
        $version = MediaVersion::withoutGlobalScopes()->find($this->versionId);
        if ($version === null) {
            return;
        }

        $service->startProcessing($version, $this->attempt);
    }

    public function failed(\Throwable $e): void
    {
        MediaVersion::withoutGlobalScopes()
            ->whereKey($this->versionId)
            ->update(['state' => MediaVersionState::Failed->value, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
