<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — delivery failures (doc 10 §3). One row per failed
 * recipient×channel attempt, linked to the event. No `tenant_id` (scoped through
 * the event); read by central admin auditor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_failures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->text('error_message');
            $table->timestamps();

            $table->index('notification_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_failures');
    }
};
