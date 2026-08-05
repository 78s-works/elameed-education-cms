<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Services\AcademicYearContext;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request; reset between requests under Octane.
        $this->app->scoped(AcademicYearContext::class);
    }
}
