<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `playback_sessions` — short-lived signed playback grants. Squashed create
 * (folds scope and media_version_id). media_asset_id / media_version_id are
 * FK-less nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->unsignedBigInteger('media_version_id')->nullable();
            $table->string('scope')->default('student');
            $table->string('token_hash');
            $table->string('device_fingerprint')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
            $table->index('token_hash');
        });

        TenantRls::enableFor('playback_sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
    }
};
