<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 11 R3.4 + R9.
 *
 * `assignment_kind` — for `assignment` sections only: `upload` (student uploads a
 * homework file that a teacher/assistant grades = "corrected") vs `onsite` (an
 * on-site exam/assignment answered in the browser). Drives which section is the
 * graded-homework part that gates progression (R5.2). NULL for non-assignment
 * sections.
 *
 * `is_required` — whether this part is compulsory. Only required parts gate the
 * next lesson (R9 — teacher controls each part, each video individually).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->string('assignment_kind')->nullable()->after('pdf_kind'); // AssignmentKind (assignment sections only)
            $table->boolean('is_required')->default(true)->after('assignment_kind');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->dropColumn(['assignment_kind', 'is_required']);
        });
    }
};
