<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lesson purchase (doc 11 R4 — "pay lesson"). A lesson can be sold on its
 * own: `is_purchasable` opens the buy path, `price_minor`/`currency` mirror the
 * course pricing columns. On purchase the student gets a lesson `enrollment` and
 * (if `availability_days` is set) the time-box window opens immediately — the
 * "week" counts from payment (decision D3). `price_minor = 0` + purchasable is a
 * free unlock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_minor')->default(0)->after('is_free_preview');
            $table->string('currency', 3)->default('EGP')->after('price_minor');
            $table->boolean('is_purchasable')->default(false)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['price_minor', 'currency', 'is_purchasable']);
        });
    }
};
