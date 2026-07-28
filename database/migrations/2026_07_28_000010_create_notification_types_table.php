<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — type catalog (doc 10 §3). GLOBAL: platform-authored by
 * central admin, not tenant-scoped (no `tenant_id`, no RLS). `key` is the
 * identity (module.entity.event); there is no name/description column. A type
 * is only live once `status = ready` (§8 lifecycle gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key')->unique();          // e.g. lessons.lesson.available
            $table->string('module');                 // NotificationModule
            $table->string('severity')->default('info'); // NotificationSeverity
            $table->boolean('is_system')->default(true);
            $table->string('status')->default('draft');  // draft|planning|ready
            $table->timestamps();

            $table->index(['module', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
