<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lessons` — standalone content units (the Course/unit grouping tables were
 * retired — VD §7; lessons are grouped by recursive packages now). Squashed
 * create: folds youtube video source, availability/extension windows, price,
 * self-reopen, access_mode and the NOT-NULL academic_year_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->enum('access_mode', ['center', 'online', 'both'])->default('both');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('video_asset_id')->nullable();
            $table->string('youtube_url', 2048)->nullable();
            $table->string('active_video_source', 16)->default('upload');
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('max_views')->nullable();
            $table->unsignedInteger('availability_days')->nullable()->default(7);
            $table->unsignedInteger('max_extensions')->default(0);
            $table->unsignedInteger('self_reopen_limit')->default(0);
            $table->unsignedInteger('extension_hours')->default(24);
            $table->boolean('is_free_preview')->default(false);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_purchasable')->default(false);
            $table->json('gating_rule')->nullable();
            $table->string('visibility')->default('visible');
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();

            $table->index('video_asset_id');
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('lessons');
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
