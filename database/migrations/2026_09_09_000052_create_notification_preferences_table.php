<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — per-user opt-out (doc 10 §4 mute layers). Absence of a
 * row means allowed; a row with `is_enabled = false` skips that one recipient
 * for that (type, channel). User-scoped (no `tenant_id`); DB-only, no UI (§9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notification_type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->string('channel');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'notification_type_id', 'channel'], 'notif_pref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
