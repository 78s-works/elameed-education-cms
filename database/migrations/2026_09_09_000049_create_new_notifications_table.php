<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — delivered message (doc 10 §3, §7 database channel). One
 * row per recipient×channel successful delivery: the in-app inbox row / sms
 * record. Tenant-owned (a delivery always has a tenant), so RLS-forced like
 * every other tenant table.
 *
 * `new_` prefix: the legacy simple `notifications` table (2026-07-02) still
 * exists and backs `/me/notifications`. The engine writes here to avoid
 * colliding with it (doc 10 assumption 1: revisit if a legacy table exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'is_read']);
            $table->index('notification_event_id');
        });

        TenantRls::enableFor('new_notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('new_notifications');
    }
};
