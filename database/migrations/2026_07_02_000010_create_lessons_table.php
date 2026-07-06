<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lessons` (03_Data_Model.md §3, FR-M04-02..07). Tenant-scoped.
 *
 * `video_asset_id` is a nullable logical reference to media_assets (set by the
 * self-hosted video pipeline in the Media step) — intentionally NOT a hard FK,
 * to avoid a circular dependency with media_assets.lesson_id (attachments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedBigInteger('video_asset_id')->nullable()->index();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('max_views')->nullable();       // P1.5 (view caps)
            $table->boolean('is_free_preview')->default(false);
            $table->json('gating_rule')->nullable();                // {requires_exam_id} — P1.5

            $table->string('visibility')->default('visible');
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'unit_id']);
            $table->index(['tenant_id', 'course_id']);
        });

        TenantRls::enableFor('lessons');
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
