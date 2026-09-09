<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform role templates (M20) — the blueprints every new tenant is stamped
 * from. GLOBAL, not tenant data: no tenant_id, and only the platform admin
 * edits them. Copies already handed to a tenant are never touched by an edit
 * here; a separate, explicit resync command pushes changes to existing tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // System templates map 1:1 to a membership kind and are locked inside
            // every tenant. Free templates are starter bundles the teacher owns.
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('role_template_permission', function (Blueprint $table) {
            $table->foreignId('role_template_id')->constrained('role_templates')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_template_id', 'permission_id'], 'role_template_permission_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_template_permission');
        Schema::dropIfExists('role_templates');
    }
};
