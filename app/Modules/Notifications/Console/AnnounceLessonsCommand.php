<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Notifications\Services\Events\LessonAnnouncer;
use App\Modules\Notifications\Support\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * Announces lessons that became available on their own — a `visible` lesson whose
 * `publish_at` has just passed. Nothing flips a row when that moment arrives
 * (availability is evaluated in the read queries), so without this the students
 * of a scheduled lesson would never be told it went live.
 *
 * Safe to run as often as you like: LessonAnnouncer refuses to announce a lesson
 * twice.
 */
class AnnounceLessonsCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'notifications:announce-lessons {--hours=48 : How far back a newly-live publish_at still counts}';

    protected $description = 'Notify students about lessons whose scheduled publish time has arrived';

    public function handle(LessonAnnouncer $announcer): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));

        $lessons = Lesson::query()
            ->withoutGlobalScopes()
            ->where('visibility', ContentVisibility::Visible->value)
            ->whereNotNull('publish_at')
            ->whereBetween('publish_at', [$since, now()])
            ->get();

        $announced = 0;

        foreach ($lessons as $lesson) {
            $sent = $this->inTenantContext(
                (int) $lesson->tenant_id,
                fn (): bool => $announcer->announce($lesson),
            );

            if ($sent) {
                $announced++;
            }
        }

        $this->info(sprintf('Announced %d newly available lesson(s).', $announced));

        return self::SUCCESS;
    }
}
