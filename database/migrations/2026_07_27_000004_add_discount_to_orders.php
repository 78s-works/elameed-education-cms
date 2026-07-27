<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the pre-discount subtotal and the coupon discount on each order (M21),
 * so `total_minor` (already the payable amount) can be explained. `coupon_id`
 * already existed; these make the discount auditable and surface it in receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('subtotal_minor')->nullable()->after('total_minor');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_minor', 'discount_minor']);
        });
    }
};
