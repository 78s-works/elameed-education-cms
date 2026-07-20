<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course thumbnail (EDU enhancement). A course gets its OWN small preview image
 * for cards/grids (catalogue + landing), distinct from `cover_url` (the wide
 * hero banner on the course detail page). Previously only lesson videos carried
 * a thumbnail/poster (media_assets.thumbnail_url).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('thumbnail_url', 2048)->nullable()->after('cover_url');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_url');
        });
    }
};
