<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student encrypted-HLS renditions (02_Architecture.md §7.3). Because the
 * watermark (student name + phone) is burned into the pixels, each viewer gets
 * their own AES-128-encrypted transcode of a source asset. One row per
 * (media_asset, user); the 16-byte content key is stored encrypted at rest and
 * released to the player only through the token-gated key endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_renditions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('transcoding'); // transcoding|ready|failed
            $table->string('hls_dir');                        // private-disk directory holding index.m3u8 + seg_*.ts
            $table->text('enc_key');                          // AES-128 key, encrypted at rest (Crypt)
            $table->string('iv', 32);                         // hex IV written into the playlist
            $table->unsignedInteger('segment_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['media_asset_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_renditions');
    }
};
