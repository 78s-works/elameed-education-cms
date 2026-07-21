<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher-panel review management (docs/api/engagement.md). Extends the student
 * `reviews` table so the teacher can also author curated testimonials and
 * moderate any entry:
 *   - user_id becomes NULLABLE (teacher-authored testimonials have no student)
 *   - author_name: display name for a teacher-authored testimonial
 *   - is_visible: moderation flag — only visible reviews feed the public course
 *     page, the landing `testimonials` section, and the course rating average.
 *
 * unique(course_id, user_id) still enforces one review per student per course;
 * teacher rows (NULL user_id) are exempt (MySQL treats NULLs as distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the FK before altering the column it constrains.
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('author_name')->nullable()->after('user_id');
            $table->boolean('is_visible')->default(true)->after('comment');
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['author_name', 'is_visible']);
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
