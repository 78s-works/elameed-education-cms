<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `packages.package_type_id` (B27) — the optional link from a content
 * package to one of its academic year's {@see package_types}. NULLABLE: a
 * package with no type stays valid. nullOnDelete: deleting a type nulls its
 * packages (does NOT delete them), so the type is a soft label, not an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->foreignId('package_type_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('package_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropForeign(['package_type_id']);
            $table->dropColumn('package_type_id');
        });
    }
};
