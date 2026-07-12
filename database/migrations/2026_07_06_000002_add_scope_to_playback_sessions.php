<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A playback session can now be a teacher self-preview (no enrollment), so the
 * key re-check knows which gate to apply. lesson_id becomes nullable because a
 * freshly uploaded asset may be previewed before it is attached to a lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->string('scope')->default('student')->after('media_asset_id'); // student|preview
        });

        // MySQL: drop the FK before altering the column, then re-add it nullable.
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->dropForeign(['lesson_id']);
        });
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->dropForeign(['lesson_id']);
            $table->unsignedBigInteger('lesson_id')->nullable(false)->change();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            $table->dropColumn('scope');
        });
    }
};
