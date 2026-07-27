<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson Q&A + comments (M09, FR-M09-01/03). A top-level row is a student's
 * question/comment on a lesson; a row with `parent_id` is a reply (teacher,
 * assistant, or student). `status` tracks the question lifecycle
 * (new|answered|closed); `is_hidden` powers moderation (FR-M09-04). The per-
 * teacher forum (FR-M09-02) is an aggregate query over these rows across the
 * tenant's courses — no separate storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();

            $table->text('body');
            $table->string('status')->default('new'); // new | answered | closed
            $table->boolean('is_hidden')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lesson_id', 'parent_id']);
            $table->index(['tenant_id', 'status']);
        });

        TenantRls::enableFor('comments');
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
