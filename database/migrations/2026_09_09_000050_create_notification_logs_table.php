<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — per-notification delivery log (doc 10 §3). Child of a
 * delivered `new_notifications` row; no `tenant_id` (guarded through the parent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('notification_id')->constrained('new_notifications')->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued|sent|failed
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('notification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
