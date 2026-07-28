<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lesson time-boxed access config ("Lesson Availability & Extension
 * Requests"). When `availability_days` is set, a student's access window opens
 * on first start and auto-locks after N days; they may request up to
 * `max_extensions` extensions of `extension_hours` each. NULL availability_days
 * = unlimited (current behaviour, no window).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedInteger('availability_days')->nullable()->after('max_views');
            $table->unsignedInteger('max_extensions')->default(0)->after('availability_days');
            $table->unsignedInteger('extension_hours')->default(24)->after('max_extensions');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['availability_days', 'max_extensions', 'extension_hours']);
        });
    }
};
