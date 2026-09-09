<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `package_types` — per-year soft label/grouping for packages. Squashed create
 * (folds channel + buy_alone; the transient description column was dropped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 16)->default('hybrid');
            $table->boolean('buy_alone')->default(false);
            $table->integer('sort_order')->default(0);
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
