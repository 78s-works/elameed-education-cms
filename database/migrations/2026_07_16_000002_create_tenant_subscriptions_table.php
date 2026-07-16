<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_subscriptions` — a teacher academy's plan history + teacher-billing
 * state (M03, FR-M03-03).
 *
 * Has tenant_id but is GLOBAL (no RLS / BelongsToTenant): the platform admin
 * manages it cross-tenant and the owning teacher reads it via an explicit
 * tenant_id filter — the same pattern as `tenant_user`. See 03_Data_Model.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('subscription_packages');

            // trialing | active | past_due | canceled | expired
            $table->string('status')->default('active');

            // Price locked at assignment (may differ from the plan price for a
            // new-teacher discount — FR-M03-04). Minor units + currency.
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            // Free-form billing notes (discount reason, admin actor, …).
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
