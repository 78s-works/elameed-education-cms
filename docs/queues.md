# Queues and workers

Named queues on the `database` driver — no Redis (ruled 9 Aug). Queue names live
in one place, `App\Support\Queue\QueueNames`; jobs declare theirs with
`public $queue`, so nothing depends on a string typed at a dispatch site.

## Why the split

One shared queue means the slowest job sets the delay for every other. A login
code queued behind a video transcode arrives after the student has given up, so
OTP gets its own queue and its own worker. Media work is minutes long once FFmpeg
is real. Report exports (EDU-021) walk thousands of rows and are the first jobs
that genuinely run for minutes.

| Queue | Work | Connection | Worker timeout |
|---|---|---|---|
| `otp` | login / verification codes | `database` | 30 s |
| `media` | transcode, remote processing | `database` | 120 s |
| `exports` | report exports (XLSX/PDF) | `database_long` | 900 s |
| `default` | everything else (broadcasts, one-offs) | `database` | 120 s |

## The one rule: `--timeout` must be below `retry_after`

`retry_after` is how long the queue waits before deciding a reserved job died and
handing it to someone else. `--timeout` is how long the worker allows the job to
run. If a job can run *longer* than `retry_after`, the queue re-reserves it while
it is still working and the job runs twice — silently, no failure, duplicate
output. This repo shipped with `retry_after = 90` against a default worker timeout
of 60, which was survivable only because every job was short.

`retry_after` is a property of the **connection**, not of the queue, which is why
`exports` cannot share `database`: raising that connection's window to 30 minutes
would also mean a crashed OTP job sits undelivered for 30 minutes. Hence a second
connection over the same table:

| Connection | `retry_after` | Serves |
|---|---|---|
| `database` | 180 s (`DB_QUEUE_RETRY_AFTER`) | `otp`, `media`, `default` |
| `database_long` | 1800 s (`DB_LONG_QUEUE_RETRY_AFTER`) | `exports` |

Both point at the same `jobs` table, so one `queue:failed` list and one
`queue:retry` still cover everything.

## Worker definitions (for EDU-009)

Three supervised processes. Each `--timeout` is below its connection's
`retry_after`, with room to spare:

```bash
php artisan queue:work database      --queue=otp            --timeout=30  --tries=3  --sleep=1
php artisan queue:work database      --queue=media,default  --timeout=120 --tries=3  --sleep=3
php artisan queue:work database_long --queue=exports        --timeout=900 --tries=2  --sleep=5
```

Notes for whoever wires the supervisor:

- The `otp` worker is the one that must never be starved; give it its own process
  even on a small box.
- `media,default` in that order makes media work preferred when both are pending;
  swap the order if broadcasts start mattering more than transcodes.
- `--tries=2` on exports: a failed export should surface to the teacher quickly,
  not retry for half an hour.
- Every deploy runs `php artisan queue:restart`, or workers keep executing the
  code they booted with.
- On Windows/IIS (the current production host) these run as scheduled tasks or
  under a service wrapper; there is no supervisord. EDU-009 owns that decision.

## Dispatching onto `exports`

The queue name is not enough — the long connection has to be named too, or the
job lands on `database` with its 180 s window:

```php
SomeExportJob::dispatch($args)
    ->onConnection(QueueNames::LongRunningConnection)
    ->onQueue(QueueNames::Exports);
```

A job class can also declare both, which is preferable when every dispatch of it
is long-running:

```php
public $connection = QueueNames::LongRunningConnection;
public $queue = QueueNames::Exports;
```

## Checking it in a running environment

```bash
# What is queued, and where
php artisan tinker --execute="dump(DB::table('jobs')->select('queue')->selectRaw('count(*) c')->groupBy('queue')->get()->toArray());"

# Nothing should be sitting in failed_jobs
php artisan queue:failed
```

A job that ran twice shows up as duplicated output rather than as a failure, so
the `failed_jobs` table being empty is not by itself proof the timeouts are right
— the `--timeout` < `retry_after` relationship above is what guarantees it.
