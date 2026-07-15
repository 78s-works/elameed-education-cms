<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_upload_sessions` — an authorized upload intent to the Media Host
 * (docs/MEDIA_HOST_API_v1.md §3.1/§6). The `idempotency_key` is unique so a
 * retried "start upload" reuses the same intent instead of creating a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_upload_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('media_version_id')->constrained('media_versions')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('idempotency_key', 64)->unique();
            $table->string('host_upload_id')->nullable();
            $table->string('upload_url', 2048)->nullable();
            $table->string('protocol')->default('tus');       // tus|multipart
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('max_bytes')->nullable();
            $table->string('content_type')->nullable();
            $table->string('checksum_sha256')->nullable();
            $table->string('state')->default('created');       // created|uploading|uploaded|verified|expired|failed
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'media_version_id']);
            $table->index('host_upload_id');
        });

        TenantRls::enableFor('media_upload_sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_sessions');
    }
};
