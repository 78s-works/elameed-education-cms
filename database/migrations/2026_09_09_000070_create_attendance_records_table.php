<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `attendance_records` — center attendance per student per day. Squashed create
 * (folds the later academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Which center session the student checked in for (null on a legacy
            // day-attendance row), and when the online window that check-in
            // granted runs out.
            $table->foreignId('center_session_id')->nullable()->constrained('center_sessions')->nullOnDelete();
            $table->timestamp('access_expires_at')->nullable();
            $table->date('attended_on');
            $table->string('status')->default('present');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('online');
            $table->string('external_ref')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            // Session-level: one student can check in for several sessions on the
            // same day, so the session is part of the day's uniqueness.
            $table->unique(
                ['center_id', 'user_id', 'attended_on', 'center_session_id'],
                'attendance_center_user_day_csession_unique',
            );
            $table->unique(['tenant_id', 'external_ref']);
            $table->index(['tenant_id', 'center_id', 'attended_on']);
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
