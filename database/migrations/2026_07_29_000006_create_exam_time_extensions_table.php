<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_time_extensions` (doc 11 R6) — a student's request for extra time on an
 * exam/quiz, and its staff decision. Kept separate from the lesson-window
 * `lesson_extension_requests` (which already ships) to avoid refactoring the
 * tested lesson flow; both are reviewed on the same teacher surface. A granted
 * row's `granted_minutes` is added to the exam's `duration_min` for that student
 * when the attempt timer computes remaining time. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_time_extensions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('requested_minutes')->nullable();
            $table->unsignedInteger('granted_minutes')->nullable();
            $table->string('status')->default('pending'); // ExtensionStatus: pending|granted|denied
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'exam_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });

        TenantRls::enableFor('exam_time_extensions');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_time_extensions');
    }
};
