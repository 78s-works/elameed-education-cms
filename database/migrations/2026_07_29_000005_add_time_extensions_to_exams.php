<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 11 R6 + R8 — per-student exam/quiz time extensions. `max_time_extensions`
 * caps how many time-extension requests a student may have granted for this exam
 * (0 = none, mirrors lessons.max_extensions). The actual granted minutes live in
 * `exam_time_extensions`; the attempt timer adds them to `duration_min`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->unsignedInteger('max_time_extensions')->default(0)->after('duration_min');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->dropColumn('max_time_extensions');
        });
    }
};
