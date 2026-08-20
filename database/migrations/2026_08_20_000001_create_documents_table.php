<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `documents` — the single ledger for every file the platform stores.
 *
 * Before this table, files were written from eleven call sites: some recorded a
 * row in `attachments`, some abused `media_assets`, and three recorded nothing
 * at all (assignment submissions, corrected files and landing images lived only
 * as paths inside a JSON column). Nothing could list a teacher's files, measure
 * storage, or clean up after a deleted owner.
 *
 * A document links to its owner in one of two ways, never both:
 *
 *   A. the owner row holds `document_id`  — exactly one file (a PDF section, a
 *      receipt, an invoice, a video's source);
 *   B. this row holds `documentable_*`    — many files, or the file is uploaded
 *      before its owner exists (comment attachments, assignment submissions).
 *
 * Pattern B is nullable on purpose: `POST /documents` returns an unattached row
 * and the client links it when it creates the owner. PruneUnattachedDocuments
 * collects the ones that never get linked.
 *
 * There is no `deleted_at`: deletion is permanent by product decision, so the
 * row and its blob go together in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            // What the file is for — drives disk, validation, and who may read it.
            $table->string('purpose');
            $table->string('kind');
            $table->string('visibility');
            $table->string('status')->default('ready');

            // Where the bytes actually are. `disk` is stored rather than derived
            // so a config change can never orphan an existing file.
            $table->string('disk');
            $table->string('storage_key', 1024);

            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum', 64)->nullable();

            // Pattern B. Null while an upload is still unattached.
            $table->string('documentable_type')->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();

            // duration_sec, width, height, pages — whatever the kind warrants.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'purpose']);
            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['tenant_id', 'checksum']);
        });

        TenantRls::enableFor('documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
