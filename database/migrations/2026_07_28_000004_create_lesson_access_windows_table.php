<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_access_windows` — timed access to a lesson per student. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_access_windows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('extensions_used')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'lesson_id'], 'lesson_window_unique');
            $table->index(['tenant_id', 'lesson_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('lesson_access_windows');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_access_windows');
    }
};
