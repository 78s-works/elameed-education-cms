<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `login_attempts` — audit of login attempts (FR-M11-03, P1 logging). Feeds the
 * abnormal-activity alerts in P2. `user_id` is nullable so failed attempts for a
 * non-existent / unmatched identifier are still recorded without enumeration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('identifier')->nullable();     // phone/email presented
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
