<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `wallets` — a student's balance within one tenant (03_Data_Model.md §3).
 * Balance is DERIVED from ledger_entries, never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 3)->default('EGP');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        TenantRls::enableFor('wallets');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
