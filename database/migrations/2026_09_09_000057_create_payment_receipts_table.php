<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_receipts` — manual (Vodafone Cash / InstaPay) payment proofs awaiting
 * review. Squashed create (folds the later corrected_amount_minor field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('method', ['vodafone_cash', 'instapay']);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('corrected_amount_minor')->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
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
