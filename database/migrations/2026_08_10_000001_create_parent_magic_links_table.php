<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `parent_magic_links` — passwordless guardian access (VD R11; doc 12 §2).
 * A permanent, static token per guardian: the raw token lives only in the link
 * we hand the parent (WhatsApp/SMS), and only its SHA-256 `token_hash` is stored.
 * Consuming `GET /parent/magic/{token}` mints a normal Sanctum parent session.
 *
 * Lifecycle (VD-D5, RESOLVED 2026-08-10 — permanent + revocable): the token does
 * NOT expire and is reusable; revocation is explicit (`is_active=false`, set on
 * teacher rotate/revoke). Child-state revocation is separate — a disabled/removed
 * child simply drops from the parent's list; the token stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_magic_links', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 hex — never the raw token
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['tenant_id', 'parent_user_id']);
        });

        TenantRls::enableFor('parent_magic_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_magic_links');
    }
};
