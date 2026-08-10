<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the parent multi-child switcher's active selection on the Sanctum session
 * itself (VD R11). `active_child_id` is the currently-selected child for THIS token
 * only — `POST /parent/switch` updates it; it dies with the token, so switching on
 * one device never leaks to another. Nullable + `nullOnDelete` so a deleted child
 * simply clears the selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('active_child_id')->nullable()->after('abilities')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_child_id');
        });
    }
};
