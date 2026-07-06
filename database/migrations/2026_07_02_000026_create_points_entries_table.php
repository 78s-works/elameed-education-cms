<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `points_entries` — append-only points ledger per student (FR-M19). Total score
 * is derived (sum), never stored. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('points'); // signed — allows corrections
            $table->string('reason');  // lesson.completed | exam.passed | manual
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
        });

        TenantRls::enableFor('points_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('points_entries');
    }
};
