<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `part_pass_overrides` — teacher grant that marks a gating part as passed for a
 * student. Squashed create (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_pass_overrides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('lesson_section_id')->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['lesson_section_id', 'user_id']);
            $table->index(['tenant_id', 'lesson_section_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('part_pass_overrides');
    }

    public function down(): void
    {
        Schema::dropIfExists('part_pass_overrides');
    }
};
