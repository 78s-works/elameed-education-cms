<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Center attendance (M12). One row per (center, student, day). `external_ref`
 * carries the offline center-app's client id so a sync batch applies idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->date('attended_on');
            $table->string('status')->default('present');        // present | absent
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('online');         // online | offline
            $table->string('external_ref')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['center_id', 'user_id', 'attended_on']);   // one mark per day
            $table->unique(['tenant_id', 'external_ref']);             // offline idempotency (nulls allowed)
            $table->index(['tenant_id', 'center_id', 'attended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
