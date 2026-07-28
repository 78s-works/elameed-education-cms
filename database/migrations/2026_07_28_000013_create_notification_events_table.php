<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — immutable dispatch audit (doc 10 §3, §11). One row per
 * `dispatch()` that passes the lifecycle gate: which type, tenant, business
 * entity, actor, and a CURATED audit payload only. Render variables (which may
 * carry OTP/secrets) are never stored here.
 *
 * NOT RLS-forced: audit is read cross-tenant by central admin and scoped by the
 * engine in-query. `tenant_id` is set for real dispatches (contract requires it)
 * but kept nullable to allow future system-scope events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('notification_type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();               // curated audit payload only
            $table->unsignedBigInteger('triggered_by')->nullable(); // null = "System"
            $table->timestamps();

            $table->index(['tenant_id', 'notification_type_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
