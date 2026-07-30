<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit-scoped exams (doc 11 R2 — "Unit can have an exam, optional"). An exam may
 * now belong to a unit as well as a course/lesson: `unit_id` set = the unit's
 * (optional) exam, used by the progression gate (R5.3 — first lesson of the next
 * unit is blocked until the previous unit's exam is answered). NULL = not a
 * unit exam. A unit has at most one exam by convention (enforced in the app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->foreignId('unit_id')->nullable()->after('lesson_id')->constrained('units')->nullOnDelete();
            $table->index(['tenant_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['tenant_id', 'unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
