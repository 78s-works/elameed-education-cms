<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Events\LessonCompleted;
use App\Modules\Catalog\Listeners\OpenNextLessonWindow;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Catalog\Services\StudentOwnership;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request; reset between requests under Octane.
        $this->app->scoped(AcademicYearContext::class);
        // Per-request cache of the caller's owned lesson/package id-sets, so the
        // catalogue's `owned` flag costs one pair of queries, not one per row.
        $this->app->scoped(StudentOwnership::class);
    }

    public function boot(): void
    {
        // Sequential unlock engine (B14 / VD R5): completing a lesson opens the
        // next one's window. Module listeners aren't auto-discovered, so wire it.
        Event::listen(LessonCompleted::class, OpenNextLessonWindow::class);
    }
}
