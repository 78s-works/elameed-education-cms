<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_domains` — host → tenant routing (subdomains + custom domains).
 *
 * GLOBAL table (not tenant-scoped): resolution reads it before a tenant scope
 * exists, so it carries a `tenant_id` for the mapping but has NO RLS policy.
 * See 03_Data_Model.md §3 and 02_Architecture.md §4.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('host')->unique();

            // subdomain | custom
            $table->string('type')->default('subdomain');

            $table->boolean('is_primary')->default(false);

            // Cloudflare for SaaS custom hostname id + issued-cert status (P1.5).
            $table->string('cf_custom_hostname_id')->nullable();
            $table->string('ssl_status')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
