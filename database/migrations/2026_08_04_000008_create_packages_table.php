<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `packages` — recursive content package (the Unit/Bundle replacement). Squashed
 * create (folds the media/access fields). `package_type_id` is added by a trailing
 * migration because it FKs forward to `package_types` (created later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->string('promo_video_url', 2048)->nullable();
            $table->enum('access_mode', ['center', 'online', 'both'])->default('both');
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_purchasable')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('packages');
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
