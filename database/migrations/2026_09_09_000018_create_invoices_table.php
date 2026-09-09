<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices` — sequential per-tenant invoice for a paid order. Squashed create
 * (folds the later nullable uuid public identifier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->nullable()->unique();
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
