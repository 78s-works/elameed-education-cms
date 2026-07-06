<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_user` — per-tenant membership + role ("identity global, role per-
 * tenant", 03_Data_Model.md §3). One users row can be a teacher in tenant A and
 * a parent in tenant B.
 *
 * Treated as a GLOBAL mapping table (no RLS): a user's memberships span tenants
 * and GET /me must read them all, so an RLS policy keyed on the current tenant
 * would hide the others. Tenant-facing lists (e.g. a teacher's students) scope
 * with an explicit `where tenant_id = ?` at the application layer. This is a
 * deliberate deviation from the data model's implicit scoping — flagged for
 * lead confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // teacher | assistant | student | parent
            $table->string('role');

            // active | pending | suspended
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
