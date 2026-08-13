<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package-type properties (spec B1): `channel` (center | online | hybrid) — the
 * type's delivery channel — and `buy_alone` — when true, the type's lessons are
 * sold individually (its packages are not directly purchasable; only the shown
 * lessons can be bought). Both are added with safe defaults so existing rows
 * stay valid; `channel` defaults to `hybrid` (widest), `buy_alone` to false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_types', function (Blueprint $table): void {
            $table->string('channel', 16)->default('hybrid')->after('name');
            $table->boolean('buy_alone')->default(false)->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('package_types', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'buy_alone']);
        });
    }
};
