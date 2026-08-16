<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `package_items` — recursive package membership (a package holds lessons and/or
 * child packages). Squashed create (folds the later academic_year_id scoping
 * column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->enum('item_type', ['lesson', 'package']);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'item_type', 'item_id']);
            $table->index(['package_id', 'sort_order']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('package_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
    }
};
