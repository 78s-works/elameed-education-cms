<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal course reviews (LANDING_CONTRACT_V2.md). One rating+comment per student
 * per course; powers the dynamic `testimonials` landing section and a course's
 * aggregate `rating`. The full Reviews milestone (moderation, replies) is deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);          // one review per student per course
            $table->index(['tenant_id', 'course_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
