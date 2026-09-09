<?php

namespace App\Support\Queue;

/**
 * The queues this application dispatches onto, and the worker each one expects.
 *
 * Work is split by how long it runs and how much its lateness costs, because one
 * shared queue means the slowest job sets the delay for every other: a login code
 * behind a video transcode arrives after the student has given up.
 *
 *   otp      — seconds, and worthless late. Tight worker timeout, own worker.
 *   media    — minutes once FFmpeg is real. Own worker, generous timeout.
 *   exports  — minutes over thousands of rows. Runs on the `database_long`
 *              connection, whose retry_after is long enough that a running export
 *              is never handed to a second worker.
 *   default  — everything else (broadcasts, one-off jobs).
 *
 * Worker commands and the timeout/retry_after relationship: docs/queues.md.
 */
final class QueueNames
{
    public const Otp = 'otp';

    public const Media = 'media';

    public const Exports = 'exports';

    public const Default = 'default';

    /**
     * The connection a queue must be dispatched on. Only `exports` differs: it
     * needs the long retry_after, which is a per-connection setting.
     */
    public const LongRunningConnection = 'database_long';
}
