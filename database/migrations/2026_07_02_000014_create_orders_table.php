<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders` — a checkout. Squashed create (folds the later subtotal/discount/
 * coupon fields). `coupon_id` is a plain FK-less nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->nullable();
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('item_type');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        TenantRls::enableFor('orders');
        TenantRls::enableFor('order_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
