<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `packages` (VD change set §7.4 LP-1, doc 13 Phase 5) — the recursive content
 * grouping that replaces Course + Unit + Bundle. A package is scoped to one
 * academic year, carries its own access_mode ceiling + price, and contains
 * lessons and/or sub-packages (ordered) via `package_items`.
 *
 * Tenant-scoped (+ RLS on Postgres). Addressed internally by id under the
 * tenant + academic-year scope (a foreign id 404s), mirroring standalone
 * lessons; `uuid` is kept for a future public-catalogue surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->enum('access_mode', ['center', 'online', 'both'])->default('both');
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_purchasable')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('packages');
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
