<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic user attachments (M09, FR-M09-05): images, voice notes, and files
 * posted on comments (and, later, forum posts / support messages). An attachment
 * is uploaded first (unattached), then linked to its owner when the comment is
 * created — so `attachable_*` are nullable. Type/size are validated on upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();

            $table->string('kind');                          // image | audio | file
            $table->string('storage_key');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_sec')->nullable(); // voice notes
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'uploaded_by']);
            $table->index(['attachable_type', 'attachable_id']);
        });

        TenantRls::enableFor('attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
