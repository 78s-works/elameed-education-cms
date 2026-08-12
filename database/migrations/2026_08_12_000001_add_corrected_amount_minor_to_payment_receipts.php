<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_receipts.corrected_amount_minor` — VD F4 / D13-7 (Ref B26). A reviewer may
 * approve a manual top-up for a value that differs from the student-submitted amount
 * (fat-fingered figure, partial transfer). `amount_minor` stays the original submitted
 * figure (the audit baseline); `corrected_amount_minor` holds the reviewer's value when
 * they change it, and the wallet is credited with that. NULL = approved as submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('corrected_amount_minor')->nullable()->after('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table): void {
            $table->dropColumn('corrected_amount_minor');
        });
    }
};
