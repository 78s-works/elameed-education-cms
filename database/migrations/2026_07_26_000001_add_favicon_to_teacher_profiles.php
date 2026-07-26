<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher-branding favicon (FR-M02-03). The favicon is a small brand icon the
 * SPA sets as the browser-tab `<link rel="icon">` on boot; it sits alongside
 * `logo_url` as a separate asset because tab icons want a square/ICO/PNG that
 * differs from the header logo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->string('favicon_url')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn('favicon_url');
        });
    }
};
