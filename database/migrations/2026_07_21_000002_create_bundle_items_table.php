<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bundle_items` — the contents of a package. Each row references EITHER a course
 * OR a unit (`item_type` says which; the other id is null). Deleting the bundle
 * cascades; deleting the referenced course/unit cascades the item away too, so a
 * bundle never points at content that no longer exists. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->string('item_type');                          // course | unit
            $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'bundle_id']);
        });

        TenantRls::enableFor('bundle_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
