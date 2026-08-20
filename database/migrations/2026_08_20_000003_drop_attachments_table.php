<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `attachments` is superseded by `documents`. It modelled the right idea —
 * polymorphic, uuid-addressed, tracks uploader and size — but only for the
 * comment/ticket corner of the app, while lesson materials went through
 * `media_assets` and assignment files went nowhere at all.
 *
 * Its rows carry over as Pattern B documents (`attachable_*` → `documentable_*`),
 * which the seeders now produce directly. Nothing reads this table by the time
 * this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('attachments');
    }

    public function down(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();

            $table->string('kind');
            $table->string('storage_key');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'uploaded_by']);
            $table->index(['attachable_type', 'attachable_id']);
        });

        TenantRls::enableFor('attachments');
    }
};
