<?php

use App\Modules\Commerce\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Invoices are exposed over the API (GET /invoices/{invoice}) and must bind by a
 * non-guessable key — never the sequential `number` or the raw auto-increment id
 * (which would enumerate every tenant's invoices). Add a uuid, matching the
 * no-id-enumeration convention used by orders/courses/exams.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique();
        });

        // Backfill any pre-existing rows so the column is usable immediately.
        Invoice::withoutGlobalScopes()->whereNull('uuid')->get()->each(function (Invoice $invoice): void {
            $invoice->uuid = (string) Str::orderedUuid();
            $invoice->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
