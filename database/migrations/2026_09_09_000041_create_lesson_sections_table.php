<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_sections` — the "parts" that make up a lesson. Squashed create: folds
 * kind/is_required, the restructure part-config enums (access_mode/delivery/
 * gate_rule/max_tries), youtube_url and academic_year_id. `media_asset_id` and
 * `exam_id` are FK-less columns (dormant links) keeping their legacy index names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('type');
            $table->enum('access_mode', ['center', 'online', 'both'])->nullable();
            $table->enum('delivery', ['video_upload', 'image_upload', 'pdf_upload', 'bubble_sheet'])->nullable();
            $table->enum('gate_rule', ['must_pass', 'must_submit'])->nullable();
            $table->unsignedInteger('max_tries')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->string('youtube_url', 2048)->nullable();
            $table->unsignedBigInteger('exam_id')->nullable();
            $table->string('pdf_kind')->nullable();
            $table->string('assignment_kind')->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'lesson_id']);
            $table->index('media_asset_id');
            $table->index('exam_id');
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('lesson_sections');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sections');
    }
};
