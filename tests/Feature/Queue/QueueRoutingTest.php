<?php

namespace Tests\Feature\Queue;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Jobs\SendOtpJob;
use App\Modules\Media\Jobs\StartRemoteProcessingJob;
use App\Modules\Media\Jobs\TranscodeVideoJob;
use App\Support\Queue\QueueNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Queue routing (EDU-010). Every job used to land on `default`, so a slow
 * transcode delayed the login code behind it, and `retry_after` (90 s) sat above
 * the default worker timeout (60 s) — the combination that runs a long job twice
 * without ever reporting a failure.
 *
 * These assertions are cheap and worth having because the cost of the split
 * quietly regressing is invisible in production: duplicated work, not an error.
 */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_otp_goes_to_its_own_queue(): void
    {
        Queue::fake();

        SendOtpJob::dispatch('01000000000', 'sms', OtpPurpose::Login, '123456', 1);

        Queue::assertPushedOn(QueueNames::Otp, SendOtpJob::class);
    }

    public function test_media_jobs_share_the_media_queue(): void
    {
        Queue::fake();

        TranscodeVideoJob::dispatch(1);
        StartRemoteProcessingJob::dispatch(1, 1);

        Queue::assertPushedOn(QueueNames::Media, TranscodeVideoJob::class);
        Queue::assertPushedOn(QueueNames::Media, StartRemoteProcessingJob::class);
    }

    public function test_no_job_dispatches_onto_a_queue_that_has_no_worker(): void
    {
        $known = [QueueNames::Otp, QueueNames::Media, QueueNames::Exports, QueueNames::Default];
        $jobs = [
            new SendOtpJob('01000000000', 'sms', OtpPurpose::Login, '123456', 1),
            new TranscodeVideoJob(1),
            new StartRemoteProcessingJob(1, 1),
        ];

        foreach ($jobs as $job) {
            $this->assertContains(
                $job->queue ?? QueueNames::Default,
                $known,
                $job::class.' dispatches onto a queue no worker consumes'
            );
        }
    }

    /**
     * The invariant docs/queues.md is built on. A worker whose --timeout exceeds
     * its connection's retry_after lets the queue re-reserve a job that is still
     * running, and the job's work happens twice.
     */
    public function test_every_connection_leaves_room_for_its_documented_worker_timeout(): void
    {
        // The --timeout values in docs/queues.md, per connection.
        $longestWorkerTimeout = [
            'database' => 120,      // media,default worker (otp's is 30)
            'database_long' => 900, // exports worker
        ];

        foreach ($longestWorkerTimeout as $connection => $timeout) {
            $retryAfter = (int) config("queue.connections.$connection.retry_after");

            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                "queue.connections.$connection.retry_after must exceed the $timeout s worker timeout"
            );
        }
    }

    public function test_the_long_connection_is_the_same_table_with_a_longer_window(): void
    {
        $short = config('queue.connections.database');
        $long = config('queue.connections.database_long');

        // One jobs table keeps a single failed-jobs list and one retry command.
        $this->assertSame($short['driver'], $long['driver']);
        $this->assertSame($short['table'], $long['table']);
        $this->assertGreaterThan((int) $short['retry_after'], (int) $long['retry_after']);
        $this->assertSame(QueueNames::Exports, $long['queue']);
    }
}
