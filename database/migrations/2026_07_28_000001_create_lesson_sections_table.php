<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_sections` (FR-M04-01, "Flexible Lesson Content Structure"). Turns a
 * lesson from a single-video record into an ordered list of typed content
 * sections (lecture video, assignment video, PDF, assignment, quiz). Each row
 * points at exactly one payload: a MediaAsset (media_asset_id) OR an Exam
 * (exam_id). `pdf_kind` is set only for pdf sections. Tenant-scoped.
 *
 * References to media_assets/exams are nullable logical links (not hard FKs) to
 * mirror lessons.video_asset_id and avoid cross-module circular constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();

            $table->string('type');                 // LessonSectionType
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedBigInteger('media_asset_id')->nullable()->index();
            $table->unsignedBigInteger('exam_id')->nullable()->index();
            $table->string('pdf_kind')->nullable(); // PdfKind (pdf sections only)

            $table->timestamps();

            $table->index(['tenant_id', 'lesson_id']);
        });

        TenantRls::enableFor('lesson_sections');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sections');
    }
};
