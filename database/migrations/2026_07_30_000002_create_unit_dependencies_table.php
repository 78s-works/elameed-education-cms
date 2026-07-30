<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `unit_dependencies` — configurable, NON-sequential unit prerequisites (extends
 * doc 11 R5.3 beyond "the immediately previous unit"). A teacher can say "Unit 5
 * depends on Unit 2" (`depends_on_unit_id`) or "Unit 5 depends on a specific
 * lesson/exam section inside Unit 2" (`depends_on_section_id`).
 *
 * Exactly one of `depends_on_unit_id` / `depends_on_section_id` is set per row
 * (enforced in the request layer). `trigger` + `enforcement` reuse the same
 * DependencyTrigger / DependencyEnforcement vocabulary as content_dependencies.
 * Only `mandatory` rows gate; when a unit has NO explicit rows the engine falls
 * back to the previous-unit-exam default, so existing data/tests are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_dependencies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();

            // Exactly one prerequisite target is set (enforced in the request layer).
            $table->foreignId('depends_on_unit_id')->nullable()->constrained('units')->cascadeOnDelete();
            $table->foreignId('depends_on_section_id')->nullable()->constrained('lesson_sections')->cascadeOnDelete();

            $table->string('trigger');       // DependencyTrigger: submitted|passed|completed|graded
            $table->string('enforcement');   // DependencyEnforcement: mandatory|optional

            $table->timestamps();

            $table->index(['tenant_id', 'unit_id']);
            $table->unique(['unit_id', 'depends_on_unit_id', 'depends_on_section_id'], 'unit_dep_pair_unique');
        });

        TenantRls::enableFor('unit_dependencies');
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_dependencies');
    }
};
