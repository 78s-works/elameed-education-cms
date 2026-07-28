<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_channel_settings.config` now holds per-tenant SMS/mailer sender
 * credentials encrypted at rest (model cast `encrypted:array`). Ciphertext is
 * not valid JSON, so a `json` column rejects it — widen it to `text`. The table
 * carries no rows yet, so no data conversion is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channel_settings', function (Blueprint $table): void {
            $table->text('config')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_channel_settings', function (Blueprint $table): void {
            $table->json('config')->nullable()->change();
        });
    }
};
