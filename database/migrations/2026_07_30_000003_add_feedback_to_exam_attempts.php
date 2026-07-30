<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer homework grading (doc 11 R3.4). When a teacher/assistant grades an
 * `upload`-kind homework attempt they may attach:
 *   feedback       — free-text written comment on the submission
 *   corrected_file — a pointer ({path,name,size,mime}) to an annotated/corrected
 *                    file stored on the PRIVATE assignments disk, surfaced to the
 *                    student alongside their grade once graded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->text('feedback')->nullable()->after('answers');
            $table->json('corrected_file')->nullable()->after('feedback'); // { path, name, size, mime }
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->dropColumn(['feedback', 'corrected_file']);
        });
    }
};
