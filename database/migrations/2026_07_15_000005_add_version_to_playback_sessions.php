<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a playback session to a specific video VERSION (remote provider), so a
 * token issued for v2 can't be used to reach v3 or a quarantined version. Null
 * for legacy local sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('media_version_id')->nullable()->after('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->dropColumn('media_version_id');
        });
    }
};
