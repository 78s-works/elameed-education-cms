<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B22 (VD Item 6 — payment scratch codes). Adds batch-provenance: which staff user
 * generated the code. `activation_codes` (type=wallet) already IS the scratch code —
 * denominated (amount_minor), batched, single-use, tenant-scoped, wallet-crediting on
 * redeem — so scratch codes extend it rather than duplicate a new table (see
 * docs (1)/03 §6, 13 §Item 6). `generated_by` is the only column B22 adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activation_codes', function (Blueprint $table): void {
            $table->foreignId('generated_by')->nullable()->after('center_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activation_codes', function (Blueprint $table): void {
            $table->dropForeign(['generated_by']);
            $table->dropColumn('generated_by');
        });
    }
};
