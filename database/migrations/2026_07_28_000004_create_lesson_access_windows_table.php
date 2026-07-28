<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_access_windows` ("Lesson Availability & Extension Requests"). One row
 * per (student, lesson): the time-boxed access window that opens when the
 * student confirms and starts the lesson. `expires_at = started_at +
 * availability_days`; `locked_at` is stamped once the window closes. This is the
 * source the Lesson Countdown Timer reads and the playback gate re-checks.
 *
 * Distinct from playback_sessions.expires_at (per-token concurrency TTL) — this
 * is the durable per-student learning window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_access_windows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('extensions_used')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'lesson_id'], 'lesson_window_unique');
            $table->index(['tenant_id', 'lesson_id']);
        });

        TenantRls::enableFor('lesson_access_windows');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_access_windows');
    }
};
