<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag each asset with its storage provider and its current servable version.
 * `provider` defaults to 'local' so every EXISTING asset keeps working exactly as
 * before; new remote assets are created with 'remote'. `current_version_id` is a
 * soft pointer into media_versions (set only when a version reaches `ready`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('provider')->default('local')->after('status');
            $table->unsignedBigInteger('current_version_id')->nullable()->after('provider');
            $table->index('current_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['current_version_id']);
            $table->dropColumn(['provider', 'current_version_id']);
        });
    }
};
