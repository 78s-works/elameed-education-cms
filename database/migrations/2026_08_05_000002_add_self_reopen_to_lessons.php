<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lesson automated self-reopen budget (VD R3/R4, doc 13 Phase 13, decision
 * D13-8 = add column). A student may self-reopen an expired/locked window for
 * `extension_hours` (24h) instantly, with NO staff approval, up to
 * `self_reopen_limit` times. It is the auto slice of the total extension budget:
 * the spent counter (`lesson_access_windows.extensions_used`) is shared with the
 * staff-approval flow, so `max_extensions` > `self_reopen_limit` leaves the
 * remainder as the staff-approval fallback. 0 = auto self-reopen disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedInteger('self_reopen_limit')->default(0)->after('max_extensions');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('self_reopen_limit');
        });
    }
};
