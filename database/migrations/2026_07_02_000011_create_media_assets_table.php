<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_assets` — logical media handle for a lesson. Squashed create (folds
 * provider, current_version_id + thumbnail_url, and size_bytes).
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
            $table->string('type');
            $table->string('status')->default('ready');
            $table->string('provider')->default('local');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->string('source_key')->nullable();
            $table->string('hls_path')->nullable();
            $table->string('encryption_key_ref')->nullable();
            $table->json('renditions')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('watermark_policy')->nullable();
            $table->boolean('downloadable')->default(false);
            $table->string('access_scope')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'lesson_id']);
            $table->index('current_version_id');
        });

        TenantRls::enableFor('media_assets');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
