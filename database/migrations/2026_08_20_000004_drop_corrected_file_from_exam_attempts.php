<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's corrected/annotated return used to be a JSON pointer on the
 * attempt — a storage path, a name and a size, invisible to every part of the
 * system that deals in files. It becomes a `documents` row attached to the
 * attempt (purpose `assignment_corrected`), so it can be listed, counted and
 * cleaned up like everything else.
 *
 * Student submissions inside `answers` keep their per-question structure but now
 * reference `document_uuid` instead of carrying a raw path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->dropColumn('corrected_file');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->json('corrected_file')->nullable();
        });
    }
};
