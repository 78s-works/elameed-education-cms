<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reviews` — course ratings. Squashed create (folds the later teacher review
 * fields — author_name / user_id nullability / is_visible — and the
 * academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('author_name')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
            $table->index(['tenant_id', 'course_id', 'rating']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('reviews');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
