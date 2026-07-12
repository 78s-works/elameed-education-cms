<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landing v2 renamed the layout keys (layout_1/2/3 → classic|grid|spotlight).
 * Repoint the column default and remap any rows saved under the old scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function ($table): void {
            $table->string('layout', 32)->default('classic')->change();
        });

        DB::table('teacher_profiles')
            ->where('layout', 'like', 'layout\_%')
            ->orWhereNull('layout')
            ->update(['layout' => 'classic']);
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function ($table): void {
            $table->string('layout', 32)->default('layout_1')->change();
        });
    }
};
