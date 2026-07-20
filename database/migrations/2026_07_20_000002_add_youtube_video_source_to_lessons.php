<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-source lesson video (docs/design/lesson-video-sources.md).
 *
 * A lesson may hold BOTH a protected uploaded video (existing `video_asset_id`)
 * and a YouTube link (`youtube_url`). `active_video_source` is the teacher toggle
 * that decides which one students receive; the inactive source stays stored but
 * is never exposed to students.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('youtube_url', 2048)->nullable()->after('video_asset_id');
            $table->string('active_video_source', 16)->default('upload')->after('youtube_url');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['youtube_url', 'active_video_source']);
        });
    }
};
