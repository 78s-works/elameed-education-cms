<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_progress` — watch %, resume position (03_Data_Model.md §3). One row per
 * (user, lesson) within a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('watch_percent')->default(0);
            $table->unsignedInteger('watch_seconds')->default(0);
            $table->unsignedInteger('sessions_count')->default(0);
            $table->unsignedInteger('last_position_sec')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'lesson_id']);
        });

        TenantRls::enableFor('lesson_progress');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
