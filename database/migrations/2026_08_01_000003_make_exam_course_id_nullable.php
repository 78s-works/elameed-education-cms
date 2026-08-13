<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A free_exam links to nothing — course_id must be nullable. The original exams
 * table made course_id NOT NULL (every exam belonged to a course). Relax it; the
 * FK constraint stays (a nullable FK simply allows NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Free exams have no course; the old NOT NULL schema cannot hold them.
        // Drop those rows before restoring the constraint so the modify succeeds.
        DB::table('exams')->whereNull('course_id')->delete();

        Schema::table('exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id')->nullable(false)->change();
        });
    }
};
