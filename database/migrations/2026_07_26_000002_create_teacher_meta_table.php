<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teacher_meta` — arbitrary key/value metadata a teacher manages from the panel
 * (FR-M02). Tenant-scoped; one row per (group, key). `group` namespaces the
 * entries (e.g. `seo`, `og`, `general`) so the same key can live under more than
 * one group without colliding. Powers /teacher/meta CRUD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_meta', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Lengths kept small so the (tenant_id, group, key) unique index stays
            // well within MySQL's index-size limit.
            $table->string('group', 64)->default('general');
            $table->string('key', 191);
            $table->text('value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            // No duplicate key within a group for a tenant (upsert target).
            $table->unique(['tenant_id', 'group', 'key']);
        });

        TenantRls::enableFor('teacher_meta');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_meta');
    }
};
