<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_time_extensions` — per-student extra time on a timed exam. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_time_extensions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('requested_minutes')->nullable();
            $table->unsignedInteger('granted_minutes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'exam_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('exam_time_extensions');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_time_extensions');
    }
};
