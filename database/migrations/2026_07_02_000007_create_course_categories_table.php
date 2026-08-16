<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `course_categories` — tenant-scoped taxonomy for courses. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->string('grade')->nullable();
            $table->string('subject')->nullable();
            $table->string('level')->nullable();
            $table->string('section')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('course_categories');
    }

    public function down(): void
    {
        Schema::dropIfExists('course_categories');
    }
};
