<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentation fields for the student package modal (spec F4): a `description`,
 * a `cover_url` (image) and an optional `promo_video_url`. All nullable — a
 * package renders fine without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('cover_url', 2048)->nullable()->after('description');
            $table->string('promo_video_url', 2048)->nullable()->after('cover_url');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['description', 'cover_url', 'promo_video_url']);
        });
    }
};
