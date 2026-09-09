<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `points_entries` — append-only gamification ledger. Squashed create (folds the
 * later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('points');
            $table->string('reason');
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('points_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('points_entries');
    }
};
