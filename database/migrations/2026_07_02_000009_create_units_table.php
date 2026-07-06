<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `units` — a course is structured into units/chapters (FR-M04-02). Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('visibility')->default('visible');
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'course_id']);
        });

        TenantRls::enableFor('units');
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
