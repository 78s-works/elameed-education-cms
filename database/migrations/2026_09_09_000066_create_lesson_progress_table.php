<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_progress` — per-student watch state for a lesson. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('watch_percent')->default(0);
            $table->unsignedInteger('watch_seconds')->default(0);
            $table->unsignedInteger('sessions_count')->default(0);
            $table->unsignedInteger('last_position_sec')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'lesson_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('lesson_progress');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
