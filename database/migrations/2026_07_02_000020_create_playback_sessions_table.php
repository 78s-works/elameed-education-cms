<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `playback_sessions` — issued short-lived playback tokens for concurrency/device
 * limits + analytics (03_Data_Model.md §3; 02_Architecture.md §7). The token is
 * stored HASHED; the plaintext lives only in the client's manifest/key requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->string('token_hash')->index();
            $table->string('device_fingerprint')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
        });

        TenantRls::enableFor('playback_sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
    }
};
