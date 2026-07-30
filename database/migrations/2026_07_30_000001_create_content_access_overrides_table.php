<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_access_overrides` — a teacher/assistant's MANUAL grant of direct
 * access to a specific locked target (lesson, section, or unit) for one student,
 * bypassing the normal Content Dependency / progression gates.
 *
 * Exactly one of `lesson_id` / `section_id` / `unit_id` is set per row (the
 * granted target). An override is ACTIVE while `revoked_at` is null; revoking is
 * a soft flip (the row stays for the audit trail). Checked by ContentUnlockService
 * (section gate) and LessonProgressionService (lesson/unit gate) which short-
 * circuit to unlocked when an active override covers the target.
 *
 * A unit override covers every section/lesson under it; a lesson override covers
 * that lesson + its sections; a section override covers just that section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_access_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Exactly one target is set (enforced in the request/service layer).
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable(); // null = active

            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'lesson_id']);
            $table->index(['tenant_id', 'user_id', 'section_id']);
            $table->index(['tenant_id', 'user_id', 'unit_id']);
        });

        TenantRls::enableFor('content_access_overrides');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_access_overrides');
    }
};
