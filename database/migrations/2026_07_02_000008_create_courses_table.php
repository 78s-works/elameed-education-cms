<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `courses` (03_Data_Model.md §3, FR-M04-01..05). Tenant-scoped; slug is unique
 * per tenant (public URL). Money in integer minor units. Soft-deleted (courses
 * are never hard-deleted — §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();

            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->unsignedInteger('access_days')->nullable();   // validity after purchase

            $table->string('visibility')->default('hidden');       // visible|hidden|scheduled
            $table->timestamp('publish_at')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('purchase_enabled')->default(true);
            $table->boolean('is_center')->default(false);
            $table->string('cover_url')->nullable();
            $table->unsignedInteger('points')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'visibility']);
        });

        TenantRls::enableFor('courses');
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
