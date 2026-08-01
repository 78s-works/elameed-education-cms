<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug 1 — YouTube video sections. Mirrors `lessons.youtube_url`: a video section
 * (`lecture_video`/`assignment_video`) may point at an uploaded MediaAsset
 * (`media_asset_id`) OR a YouTube link (`youtube_url`). Previously sections were
 * upload-only, so an "explain/answer video" built from a YouTube link had no
 * home and the section could not be saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->string('youtube_url', 2048)->nullable()->after('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->dropColumn('youtube_url');
        });
    }
};
