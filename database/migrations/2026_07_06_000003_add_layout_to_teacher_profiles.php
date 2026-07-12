<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landing layout selector (EDU enhancement). The three layouts share one content
 * contract (see LandingSchema); this only records which arrangement is active.
 * Per-section content is stored in the existing `landing_sections` JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->string('layout', 32)->default('layout_1')->after('landing_sections');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn('layout');
        });
    }
};
