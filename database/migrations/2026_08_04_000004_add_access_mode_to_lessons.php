<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson access_mode (VD change set §7 LP-4) — the channel ceiling every part
 * must fit within. Also pins `availability_days` to the as-built 7-day default
 * (VD R3): a lesson closes 7 days after a student opens it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->enum('access_mode', ['center', 'online', 'both'])
                ->default('both')
                ->after('course_id');

            // availability_days was added nullable (no default); default it to 7.
            $table->unsignedInteger('availability_days')->default(7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('access_mode');
            $table->unsignedInteger('availability_days')->nullable()->default(null)->change();
        });
    }
};
