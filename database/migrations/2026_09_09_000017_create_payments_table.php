<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payments` — one per payment attempt. `gateway_txn_id` UNIQUE is the primary
 * webhook idempotency guard (03_Data_Model.md §5). Nullable for wallet payments
 * (MySQL permits multiple NULLs in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('gateway');                    // paymob|fawry|wallet
            $table->string('gateway_txn_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status')->default('pending'); // pending|paid|failed
            $table->string('reference_number')->nullable(); // Fawry
            // A payable reference (Fawry) stops being payable at a fixed moment;
            // a card payment leaves this null. The reconciliation command sweeps
            // on (status, expires_at) — see fawry:reconcile.
            $table->timestamp('expires_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['status', 'expires_at']);
        });

        TenantRls::enableFor('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
