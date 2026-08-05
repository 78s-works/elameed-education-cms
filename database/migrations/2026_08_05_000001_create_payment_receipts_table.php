<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_receipts` — VD manual wallet top-ups (R9/R10; doc 12 §2, doc 13 Phase 11).
 * A student uploads a Vodafone Cash / InstaPay receipt image → `pending`; a teacher
 * or `finance`-permitted assistant approves (posts a `student_wallet` credit to the
 * ledger, idempotent on `receipt:{id}`) or rejects it. Fraud controls are human-only
 * (VD-D4) — every top-up stays `pending` until a reviewer acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // the student

            $table->enum('method', ['vodafone_cash', 'instapay']);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete(); // receipt image

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Set on approve: the student_wallet credit leg posted for this receipt.
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->string('reject_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        TenantRls::enableFor('payment_receipts');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
