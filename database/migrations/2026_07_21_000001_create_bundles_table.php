<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bundles` — a teacher-authored **package**: a priced group of courses and/or
 * units sold as one product (the P1.5 "course bundle" from 03_Data_Model.md §5,
 * now built). Buying a bundle grants an enrollment for each item it contains, so
 * every lesson/exam inside opens. Tenant-scoped; slug unique per tenant; money in
 * integer minor units; soft-deleted (retire without breaking existing access).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug');
            $table->text('description')->nullable();

            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            // Access window granted to every item on purchase. null = lifetime.
            $table->unsignedInteger('access_days')->nullable();

            $table->string('visibility')->default('hidden');       // visible|hidden|scheduled
            $table->timestamp('publish_at')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('purchase_enabled')->default(true);
            $table->string('cover_url')->nullable();
            $table->string('thumbnail_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'visibility']);
        });

        TenantRls::enableFor('bundles');
    }

    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
