<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_versions` — one row per version of a video on the Media Host
 * (docs/MEDIA_HOST_API_v1.md §5). Versioning makes replacement atomic: a new
 * version is prepared while the current `ready` one keeps serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->unsignedInteger('version');

            $table->string('provider')->default('remote');   // local|remote
            $table->string('state')->default('pending');      // MediaVersionState

            $table->string('host_video_id')->nullable();      // host's video id
            $table->string('playback_id')->nullable();        // host's playback id (delivery)
            $table->unsignedInteger('duration_sec')->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique(['media_asset_id', 'version']);
            $table->index(['tenant_id', 'state']);
            $table->index('host_video_id');
        });

        TenantRls::enableFor('media_versions');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_versions');
    }
};
