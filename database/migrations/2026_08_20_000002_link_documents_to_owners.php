<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pattern A wiring: every table that owns exactly one file gets a `*_document_id`
 * FK, and the ad-hoc string column it replaces is dropped in the same breath.
 *
 * The project is pre-production, so nothing is kept for compatibility — a URL or
 * storage key living in a varchar is exactly the problem `documents` exists to
 * remove, and leaving both would invite code that reads the stale one.
 *
 * `nullOnDelete` throughout: losing a file must never delete the lesson section,
 * receipt or invoice that referenced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            // PDF / image parts. Video parts keep pointing at media_asset_id.
            $table->foreignId('document_id')->nullable()->after('media_asset_id')
                ->constrained('documents')->nullOnDelete();
        });

        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->after('currency')
                ->constrained('documents')->nullOnDelete();
            $table->dropConstrainedForeignId('attachment_id');
        });

        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->foreignId('logo_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('favicon_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('cover_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->dropColumn(['logo_url', 'favicon_url', 'cover_url']);
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->foreignId('cover_document_id')->nullable()->after('cover_url')
                ->constrained('documents')->nullOnDelete();
            $table->dropColumn('cover_url');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->dropColumn('pdf_url');
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            // The raw upload and the poster are ordinary documents; everything
            // else about a video (hls_path, renditions, keys) stays here.
            $table->foreignId('source_document_id')->nullable()->after('provider')
                ->constrained('documents')->nullOnDelete();
            $table->foreignId('thumbnail_document_id')->nullable()->after('source_document_id')
                ->constrained('documents')->nullOnDelete();
            $table->dropColumn(['source_key', 'thumbnail_url']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropConstrainedForeignId('thumbnail_document_id');
            $table->string('source_key')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pdf_document_id');
            $table->string('pdf_url')->nullable();
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cover_document_id');
            $table->string('cover_url', 2048)->nullable();
        });

        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_document_id');
            $table->dropConstrainedForeignId('favicon_document_id');
            $table->dropConstrainedForeignId('cover_document_id');
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('cover_url')->nullable();
        });

        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->cascadeOnDelete();
        });

        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
