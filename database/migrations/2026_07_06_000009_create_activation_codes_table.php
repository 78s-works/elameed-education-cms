<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recharge / activation codes (M12). Two kinds: `wallet` (credits the student's
 * wallet by `amount_minor`) and `course` (enrolls them in `course_id`). Sold at
 * centers; redeemed once by a student. One-time status is the redemption guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('type');                              // wallet | course
            $table->unsignedBigInteger('amount_minor')->nullable(); // wallet codes
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete(); // course codes
            $table->foreignId('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->string('batch')->nullable();
            $table->string('status')->default('active');         // active | redeemed | disabled
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_codes');
    }
};
