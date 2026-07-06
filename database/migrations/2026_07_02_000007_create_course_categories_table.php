<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `course_categories` — teacher's own taxonomy (FR-M04-04). Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('grade')->nullable();
            $table->string('subject')->nullable();
            $table->string('level')->nullable();
            $table->string('section')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        TenantRls::enableFor('course_categories');
    }

    public function down(): void
    {
        Schema::dropIfExists('course_categories');
    }
};
