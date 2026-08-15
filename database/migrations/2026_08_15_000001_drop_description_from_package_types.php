<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unused `description` column from `package_types`. The type is a short
 * label (name + channel + buy_alone); its description was never surfaced to
 * students and is removed from the authoring form. `down()` restores the nullable
 * text column so the migration is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('package_types', function (Blueprint $table): void {
            $table->text('description')->nullable();
        });
    }
};
