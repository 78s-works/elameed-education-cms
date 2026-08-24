<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `refunds` — one row per refund posted against a paid order. Append-only in
 * practice (never edited): the money side is a balanced ledger post and this row
 * is its business-level record (who refunded, why, how much, where it went).
 *
 * Partial refunds are allowed, so an order can carry several rows; the order
 * flips to `refunded` only when the refunded total reaches the order total. The
 * sales ledger subtracts SUM(amount_minor) from the paid figure, which is why
 * the amount lives here and not as a single column on `orders`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            // Where the money went back to. `wallet` is the only live destination
            // (gateway refund APIs are not wired yet); `offline` records a refund
            // settled outside the platform (cash at a center) for the books.
            $table->string('destination')->default('wallet');
            $table->string('reason')->nullable();
            // True when this refund revoked the access the order had granted.
            $table->boolean('revoked_access')->default(false);
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        TenantRls::enableFor('refunds');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
