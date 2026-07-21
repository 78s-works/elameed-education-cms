<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package items can now bundle a full **course**, a **unit** (chapter), OR an
 * individual **lesson** (part of a course). Adds `bundle_items.lesson_id` alongside
 * the existing `course_id` / `unit_id`; exactly one is set per row (per `item_type`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_items', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('unit_id')->constrained('lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bundle_items', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->dropColumn('lesson_id');
        });
    }
};
