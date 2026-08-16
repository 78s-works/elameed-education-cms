<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin a student to their academic year (grade). Set at registration from the
 * teacher's real academic-year list; the ResolveAcademicYear middleware reads it
 * to scope every student content query to their own year (server-authoritative).
 *
 * Nullable + nullOnDelete: legacy/online students may have no year yet, and
 * deleting a year must NOT delete the student profile (only unpin it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->nullOnDelete();

            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
