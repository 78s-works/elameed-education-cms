<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B20 (VD Item 4) — Center ID-codes. A DIFFERENT concept from `activation_codes`
 * (M12, random one-time wallet/course recharge). These are *sequential*,
 * grade-encoded student-identity codes, minted in batches per physical center,
 * handed to students so that at sign-up the code binds them to that center + a
 * grade (1/2/3 = 1st/2nd/3rd secondary) + study_mode. A separate table because
 * the semantics (sequential + grade prefix + per-center counter + register-time
 * identity binding) share no columns with the redemption-grant activation_codes.
 *
 *   code         — grade-encoded, per-center-unique string "{grade}-{centerId}-{seq}".
 *   sequence     — the running counter within (center_id, grade); the "sequential" guarantee.
 *   grade        — 1|2|3; also the code's leading digit (the encoded prefix).
 *   status       — reuses CodeStatus vocab: active (=unused) | redeemed (=used) | disabled.
 *   batch_id     — uuid grouping one generate call, so a whole batch lists/prints together.
 *   generated_by — the teacher/assistant who ran the batch (nullOnDelete).
 *   used_by/used_at — stamped when a student registers with the code (register-time
 *                     consumer is a follow-up; columns exist so "used/unused" is queryable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_id_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->unsignedTinyInteger('grade');                 // 1 | 2 | 3
            $table->unsignedInteger('sequence');                  // per (center_id, grade)
            $table->string('code', 40);
            $table->string('status')->default('active');          // active | redeemed | disabled
            $table->uuid('batch_id');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Tenant-wide code uniqueness + sequential integrity per center+grade.
            $table->unique(['tenant_id', 'code'], 'center_id_codes_tenant_code_unique');
            $table->unique(['tenant_id', 'center_id', 'grade', 'sequence'], 'center_id_codes_center_grade_seq_unique');
            $table->index(['tenant_id', 'center_id', 'status'], 'center_id_codes_center_status_index');
            $table->index(['tenant_id', 'batch_id'], 'center_id_codes_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_id_codes');
    }
};
