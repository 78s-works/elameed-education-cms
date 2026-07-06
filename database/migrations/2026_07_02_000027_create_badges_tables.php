<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `badges` (teacher-defined) + `student_badges` (awarded). Threshold badges are
 * auto-awarded when a student's total points reach `points_threshold` (FR-M19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('points_threshold')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('student_badges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->timestamp('awarded_at')->nullable();

            $table->unique(['tenant_id', 'user_id', 'badge_id']);
        });

        TenantRls::enableFor('badges');
        TenantRls::enableFor('student_badges');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_badges');
        Schema::dropIfExists('badges');
    }
};
