<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `parent_links` — links a parent (a user with a `parent` membership) to their
 * child student within one academy (FR-M13). Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relation')->nullable(); // father | mother | guardian
            $table->timestamps();

            $table->unique(['tenant_id', 'parent_user_id', 'student_user_id']);
            $table->index(['tenant_id', 'parent_user_id']);
            $table->index(['tenant_id', 'student_user_id']);
        });

        TenantRls::enableFor('parent_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_links');
    }
};
