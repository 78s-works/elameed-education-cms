<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Degree-of-success on exams that back quiz/homework parts (VD change set §7,
 * LP-11/LP-12). Generalises the single `pass_percent` into a mode + value pair,
 * an optional absolute total, and a grading mode:
 *
 *   pass_mode    — percent | marks.
 *   pass_value   — the threshold (0–100 for percent, absolute marks otherwise).
 *   total_marks  — the exam's full marks (required for `marks` mode / auto-grade).
 *   grading_mode — manual | auto (auto only for bubble-sheet delivery).
 *
 * Existing rows migrate pass_percent → pass_mode=percent / pass_value=pass_percent.
 * `pass_percent` is kept for the legacy runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->enum('pass_mode', ['percent', 'marks'])->default('percent')->after('pass_percent');
            $table->decimal('pass_value', 8, 2)->nullable()->after('pass_mode');
            $table->decimal('total_marks', 8, 2)->nullable()->after('pass_value');
            $table->enum('grading_mode', ['manual', 'auto'])->default('manual')->after('total_marks');
        });

        // Carry the legacy percentage threshold into the new pair.
        DB::table('exams')
            ->whereNull('pass_value')
            ->update([
                'pass_mode' => 'percent',
                'pass_value' => DB::raw('pass_percent'),
            ]);
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->dropColumn(['pass_mode', 'pass_value', 'total_marks', 'grading_mode']);
        });
    }
};
