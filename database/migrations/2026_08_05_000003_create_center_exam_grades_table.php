<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `center_exam_grades` (VD R12, doc 13 Phase 15, decision D13-9) — a paper
 * (offline, in-center) exam score recorded against a student's account so it
 * surfaces in the student's results and the parent portal alongside online
 * exam attempts. Lightweight row (not a full center-exam entity): no questions,
 * no attempt lifecycle — a center helper simply types the score.
 *
 * Tenant-scoped + year-scoped: a grade belongs to exactly one academic year, so
 * writes run under the `X-Academic-Year` middleware (BelongsToAcademicYear).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_exam_grades', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');                       // paper-exam label, e.g. "Monthly test — Algebra"
            $table->decimal('total_marks', 8, 2);
            $table->decimal('score', 8, 2);
            $table->date('sat_on');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'student_user_id']);
        });

        TenantRls::enableFor('center_exam_grades');
    }

    public function down(): void
    {
        Schema::dropIfExists('center_exam_grades');
    }
};
