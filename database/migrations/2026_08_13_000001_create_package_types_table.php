<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `package_types` (B27) — a teacher-defined category/label for content
 * {@see packages}, scoped to one tenant and one academic year. The teacher
 * creates the types inside a year, then tags each content package with one of
 * that year's types. The year is the ceiling: a package may only reference a
 * type from its own academic year (enforced in PackageRequest).
 *
 * Tenant-scoped (+ RLS on Postgres, dormant on MySQL). Cascade-deletes with its
 * academic year; addressed publicly by uuid, mirroring academic_years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_types', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'academic_year_id', 'name']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('package_types');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_types');
    }
};
