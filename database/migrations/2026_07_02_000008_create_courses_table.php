<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `courses` — tenant catalog root. Squashed create (folds descriptive fields,
 * thumbnail/promo, the is_center→access_mode tri-state, and academic_year_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('learning_outcomes')->nullable();
            $table->json('requirements')->nullable();
            $table->json('audience')->nullable();
            $table->json('parts')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->unsignedInteger('access_days')->nullable();
            $table->string('visibility')->default('hidden');
            $table->timestamp('publish_at')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('purchase_enabled')->default(true);
            $table->enum('access_mode', ['center', 'online', 'both'])->default('both');
            $table->string('cover_url')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('promo_video_url', 2048)->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'visibility']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('courses');
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
