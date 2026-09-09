<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `academic_years` — top-level per-tenant content container (VD change set).
 * Tenant-scoped; addressed publicly by uuid. Content tables gain their
 * `academic_year_id` in a later phase — this migration only creates the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        TenantRls::enableFor('academic_years');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
