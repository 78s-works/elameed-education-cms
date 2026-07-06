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
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        TenantRls::enableFor('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
