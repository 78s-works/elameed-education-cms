<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount coupons / promo codes (M21, FR-M21-01/02). Tenant-scoped. A coupon is
 * either `percent` (value = 0–100) or `fixed` (value = minor units). Optionally
 * scoped to one course; otherwise it applies to the whole content subtotal. The
 * teacher absorbs the discount (it reduces their earnings at fulfilment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('code');
            $table->string('type')->default('percent');   // percent | fixed
            $table->unsignedInteger('value');              // percent 1..100, or minor units
            $table->unsignedBigInteger('course_id')->nullable(); // null = whole cart
            $table->unsignedBigInteger('min_subtotal_minor')->nullable();
            $table->unsignedInteger('usage_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        TenantRls::enableFor('coupons');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
