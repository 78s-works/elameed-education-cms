<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `center_id_codes` — pre-generated per-center student ID codes. Squashed create
 * (folds the NOT-NULL academic_year_id scoping column, incl. its custom index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_id_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->unsignedInteger('sequence');
            $table->string('code', 40);
            $table->string('status')->default('active');
            $table->char('batch_id', 36);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'center_id_codes_tenant_code_unique');
            $table->unique(['tenant_id', 'center_id', 'grade', 'sequence'], 'center_id_codes_center_grade_seq_unique');
            $table->index(['tenant_id', 'center_id', 'status'], 'center_id_codes_center_status_index');
            $table->index(['tenant_id', 'batch_id'], 'center_id_codes_batch_index');
            $table->index(['tenant_id', 'academic_year_id'], 'center_id_codes_tenant_year_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_id_codes');
    }
};
