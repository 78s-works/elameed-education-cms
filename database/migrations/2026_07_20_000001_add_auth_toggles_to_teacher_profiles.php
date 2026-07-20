<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-academy access controls (FR-M02): a teacher can close sign-in and/or new
 * self-registration for their own tenant. Both default ON. When sign-in is off,
 * ONLY the teacher may still log in (to reach their panel and re-open it) —
 * assistants, students, and parents are all blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->boolean('login_enabled')->default(true)->after('hide_ranking');
            $table->boolean('registration_enabled')->default(true)->after('login_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['login_enabled', 'registration_enabled']);
        });
    }
};
