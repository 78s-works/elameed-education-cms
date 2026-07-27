<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Public-facing uuid for tenant_domains, so the teacher domain API can address a
 * row without exposing its bigint id (M02, custom domains). Back-fills existing
 * rows before adding the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        foreach (DB::table('tenant_domains')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('tenant_domains')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('tenant_domains', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
