<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `favorites` — a student's wishlisted content, EITHER a standalone lesson OR a
 * recursive package (`target_type`/`target_id`, VD §7 — `courses` retired).
 * Squashed create (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type');           // 'lesson' | 'package'
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'academic_year_id']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
        });

        TenantRls::enableFor('favorites');
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
