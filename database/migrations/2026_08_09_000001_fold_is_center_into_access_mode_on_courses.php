<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold the legacy binary `courses.is_center` into the tri-state `access_mode`
 * (VD change set doc 12 R2 — "is_center folds into access_mode"). Courses now
 * carry the same channel enum as packages/lessons/lesson_sections (LP-4), so the
 * center/online split has one representation across every content surface.
 *
 * Backfill preserves the old binary exactly: is_center=true → `center`,
 * is_center=false → `online` (there was no "both" for courses). `both` remains
 * the column default for the enum's sake, though no course-create path exists
 * (teacher course CRUD retired — courses are seed-only + read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->enum('access_mode', ['center', 'online', 'both'])
                ->default('both')
                ->after('is_center');
        });

        DB::statement("UPDATE courses SET access_mode = CASE WHEN is_center = 1 THEN 'center' ELSE 'online' END");

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('is_center');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_center')->default(false)->after('purchase_enabled');
        });

        DB::statement("UPDATE courses SET is_center = CASE WHEN access_mode = 'center' THEN 1 ELSE 0 END");

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('access_mode');
        });
    }
};
