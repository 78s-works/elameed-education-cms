<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_dependencies` ("Content Dependencies & Unlock Rules", extends
 * FR-M04-06 beyond exam-only gating). A dependent section stays locked until a
 * `trigger` action on the prerequisite section is satisfied. `enforcement`
 * distinguishes hard gates (mandatory) from advisory hints (optional).
 *
 * A section may depend on many others; the pair is unique. Both references are
 * hard FKs into lesson_sections and cascade on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_dependencies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('depends_on_section_id')->constrained('lesson_sections')->cascadeOnDelete();

            $table->string('trigger');       // DependencyTrigger: submitted|passed|completed
            $table->string('enforcement');   // DependencyEnforcement: mandatory|optional

            $table->timestamps();

            $table->unique(['section_id', 'depends_on_section_id'], 'content_dep_pair_unique');
            $table->index(['tenant_id', 'section_id']);
        });

        TenantRls::enableFor('content_dependencies');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_dependencies');
    }
};
