<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_dependencies` — section-to-section prerequisite edges. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_dependencies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('depends_on_section_id')->constrained('lesson_sections')->cascadeOnDelete();
            $table->string('trigger');
            $table->string('enforcement');
            $table->timestamps();

            $table->unique(['section_id', 'depends_on_section_id'], 'content_dep_pair_unique');
            $table->index(['tenant_id', 'section_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('content_dependencies');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_dependencies');
    }
};
