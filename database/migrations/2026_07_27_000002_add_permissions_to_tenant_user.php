<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-membership granular permissions (M18, FR-M18-02). Only meaningful for the
 * `assistant` role — the list of surfaces a teacher has delegated to that
 * assistant. Null / empty = no delegated access. Teachers implicitly hold every
 * permission, so their rows leave this null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
