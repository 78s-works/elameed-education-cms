<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-asset stored byte size, so a tenant's media footprint can be summed and
 * enforced against the subscription package `storage_mb` limit (FR-M03-02).
 * Nullable — assets uploaded before this landed count as 0 until back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('size_bytes')->nullable()->after('duration_sec');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('size_bytes');
        });
    }
};
