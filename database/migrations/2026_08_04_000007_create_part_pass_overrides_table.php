<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `part_pass_overrides` (VD change set §7 LP-D3). A teacher (or a
 * `permission:homework` assistant) manually marks a student as having PASSED a
 * must_pass part after they exhaust their retakes. Progression treats an override
 * row as a pass regardless of score/tries. Tenant-scoped; one row per (part,
 * student).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_pass_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lesson_section_id')->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['lesson_section_id', 'user_id']);
            $table->index(['tenant_id', 'lesson_section_id']);
        });

        TenantRls::enableFor('part_pass_overrides');
    }

    public function down(): void
    {
        Schema::dropIfExists('part_pass_overrides');
    }
};
