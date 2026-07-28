<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-academy student registration verification mode. Teachers can keep the
 * current auto-verification flow or require an OTP before a new student becomes
 * active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('registration_verification_mode', 16)->default('auto')->after('registration_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('registration_verification_mode');
        });
    }
};
