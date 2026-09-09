<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an academy has actually paid the platform (ADM-16).
 *
 * `tenant_subscriptions` records the plan an academy is ON; nothing recorded
 * whether they ever paid for it, so the console could not answer the most basic
 * commercial question about a customer. One row per charge, recorded by an
 * admin (platform subscriptions are settled by transfer today, not through a
 * gateway) or, later, by a payment integration writing the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscription_charges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // The plan the charge was raised against. Kept nullable so history
            // survives a subscription row being removed.
            $table->foreignId('tenant_subscription_id')->nullable()
                ->constrained('tenant_subscriptions')->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('EGP');

            // paid | pending | failed | refunded
            $table->string('status')->default('paid');
            // manual | bank_transfer | instapay | card | wallet | other
            $table->string('method')->default('manual');

            // The billing period this charge covers, so a gap in payment is
            // visible as a gap in periods rather than inferred from dates.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('charged_at');

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'charged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscription_charges');
    }
};
