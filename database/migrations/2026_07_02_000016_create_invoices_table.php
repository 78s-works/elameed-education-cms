<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices` — sequential, gap-free number per tenant (03_Data_Model.md §5) for
 * tax/audit. ETA e-receipt (eta_receipt_uuid) is a final-phase addition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('pdf_url')->nullable();
            $table->uuid('eta_receipt_uuid')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        TenantRls::enableFor('invoices');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
