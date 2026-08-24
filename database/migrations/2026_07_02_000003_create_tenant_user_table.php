<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_user` — membership pivot (a user's role within a tenant). Squashed
 * create. Authority is NOT stored here: roles carry it (M20), so the membership
 * row says what KIND of member this is and nothing about what they may do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('pending');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'role']);
            $table->index(['tenant_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
