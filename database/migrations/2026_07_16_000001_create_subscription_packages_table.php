<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `subscription_packages` — teacher subscription plans (M03, FR-M03-01/02).
 *
 * GLOBAL table (not tenant-scoped): plans are defined once by the platform and
 * offered to every teacher academy, so there is no tenant_id / RLS. See
 * 03_Data_Model.md §1 & §3 (global tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Recurring price in integer minor units (piastres) + currency.
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('interval')->default('monthly'); // monthly | yearly

            // Free-trial length granted on assignment (FR-M03-04). 0 = no trial.
            $table->unsignedInteger('trial_days')->default(0);

            // Enforceable limits (FR-M03-02). Canonical keys: max_students,
            // max_courses, storage_mb, max_assistants. A null value = unlimited.
            $table->json('limits')->nullable();

            // Whether the plan can be offered to new tenants (retired via soft delete).
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_packages');
    }
};
