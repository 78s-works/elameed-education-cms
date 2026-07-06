<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ledger_entries` — append-only double-entry ledger (03_Data_Model.md §3, §5;
 * 02_Architecture.md §8). NEVER updated or deleted. `idempotency_key` UNIQUE so
 * a replayed webhook/operation cannot double-post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();

            // student_wallet | teacher_earnings | platform_commission | gateway_clearing
            $table->string('account');
            $table->string('direction'); // debit | credit
            $table->unsignedBigInteger('amount_minor');

            $table->string('ref_type')->nullable(); // order | payout | refund | adjustment
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('idempotency_key')->unique();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'account']);
            $table->index(['wallet_id', 'account']);
        });

        TenantRls::enableFor('ledger_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
