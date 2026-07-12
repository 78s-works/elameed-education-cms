<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer, marketing-grade course page (EDU enhancement): subtitle, learning
 * outcomes, requirements, target audience, a curriculum "parts" summary (a
 * teacher-authored outline, distinct from the real units→lessons tree), and a
 * public promo/intro video URL (a marketing teaser — unencrypted by design).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('subtitle')->nullable()->after('title');
            $table->json('learning_outcomes')->nullable()->after('description'); // string[]
            $table->json('requirements')->nullable()->after('learning_outcomes'); // string[]
            $table->json('audience')->nullable()->after('requirements');           // string[]
            $table->json('parts')->nullable()->after('audience');                  // [{title,lessons_count,duration_min}]
            $table->string('promo_video_url', 2048)->nullable()->after('cover_url');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['subtitle', 'learning_outcomes', 'requirements', 'audience', 'parts', 'promo_video_url']);
        });
    }
};
