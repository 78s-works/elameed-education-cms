<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_assets` — abstraction over video / PDF / file / link (03_Data_Model.md §3).
 * Tenant-scoped. `lesson_id` links attachments (pdf|file|link) to a lesson;
 * videos (hls_video) are referenced the other way, by lessons.video_asset_id.
 * Video-specific columns (hls_path, encryption_key_ref, renditions) are filled
 * by the self-hosted pipeline in the Media step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();

            $table->string('type');                          // hls_video|pdf|file|link
            $table->string('status')->default('ready');      // uploading|transcoding|ready|failed
            $table->string('title')->nullable();

            $table->string('source_key')->nullable();        // object-storage path of original
            $table->string('hls_path')->nullable();          // manifest path (video)
            $table->string('encryption_key_ref')->nullable();
            $table->json('renditions')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->string('url', 2048)->nullable();         // files/links
            $table->string('watermark_policy')->nullable();
            $table->boolean('downloadable')->default(false);
            $table->string('access_scope')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'lesson_id']);
        });

        TenantRls::enableFor('media_assets');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
