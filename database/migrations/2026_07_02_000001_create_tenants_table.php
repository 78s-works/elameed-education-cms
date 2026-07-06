<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants` — one row per teacher academy.
 *
 * GLOBAL table (not tenant-scoped): it is the registry consulted to resolve a
 * request to a tenant, so it must be readable before any tenant scope exists.
 * No `tenant_id`, no RLS. See 03_Data_Model.md §1 & §3 and 02_Architecture.md §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('name');

            // active | suspended | under_review | expired  (FR-M01-02)
            $table->string('status')->default('under_review')->index();

            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Hybrid escape hatch: a VIP tenant graduated to a dedicated DB
            // stores its connection name here (02_Architecture.md §4.1).
            $table->string('dedicated_db_connection')->nullable();

            // Teacher subscription package (subscription_packages is P1.5, so
            // no FK constraint yet — added when that table lands).
            $table->unsignedBigInteger('package_id')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
