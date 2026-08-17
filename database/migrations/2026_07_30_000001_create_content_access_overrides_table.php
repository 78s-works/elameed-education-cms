<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_access_overrides` — manual per-student access grants (lesson or
 * section). Squashed create (folds academic_year_id). (`courses`/units retired — VD §7.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_access_overrides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lesson_sections')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'lesson_id']);
            $table->index(['tenant_id', 'user_id', 'section_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('content_access_overrides');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_access_overrides');
    }
};
