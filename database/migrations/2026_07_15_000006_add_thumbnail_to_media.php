<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-video thumbnail (poster). Stored per version (remote: from the processing
 * callback; each version can differ) and surfaced on the asset as the current
 * poster (remote: copied on promotion; local: an FFmpeg frame set at upload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('thumbnail_url', 2048)->nullable()->after('current_version_id');
        });
        Schema::table('media_versions', function (Blueprint $table): void {
            $table->string('thumbnail_url', 2048)->nullable()->after('playback_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', fn (Blueprint $t) => $t->dropColumn('thumbnail_url'));
        Schema::table('media_versions', fn (Blueprint $t) => $t->dropColumn('thumbnail_url'));
    }
};
