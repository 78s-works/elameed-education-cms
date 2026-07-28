<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — templates (doc 10 §3). A `(type, channel, scope,
 * tenant_id)` binding + activation flag; HOLDS NO TEXT (copy lives in
 * translations). `tenant_id` is NULL for system rows, set for tenant overrides.
 *
 * NOT RLS-forced: this table intentionally mixes system (tenant_id NULL) and
 * cross-tenant rows, read by both the central admin and the engine, which scope
 * every query explicitly (doc 10 §6). A forced tenant RLS predicate would hide
 * the NULL system rows entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('notification_type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->string('scope')->default('system');    // system|tenant
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('channel');                      // NotificationChannel
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamps();

            // One template per (type, channel) within a scope+tenant.
            $table->unique(['notification_type_id', 'channel', 'scope', 'tenant_id'], 'notif_tpl_binding_unique');
            $table->index(['tenant_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
