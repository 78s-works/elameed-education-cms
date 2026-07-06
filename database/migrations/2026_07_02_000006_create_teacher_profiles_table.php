<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teacher_profiles` — per-tenant branding + landing config (03_Data_Model.md §3,
 * FR-M02-03/04). One row per tenant.
 *
 * TENANT-SCOPED: carries `tenant_id` and uses the BelongsToTenant scope. On
 * Postgres it also gets an RLS policy (TenantRls); on MySQL that call no-ops and
 * the app-level scope is the only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();

            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('primary_color', 9)->nullable();   // #RRGGBB[AA]
            $table->string('secondary_color', 9)->nullable();
            $table->text('bio')->nullable();
            $table->json('contact')->nullable();              // {phone,email,whatsapp,address}
            $table->json('socials')->nullable();              // {facebook,youtube,instagram,...}
            $table->json('landing_sections')->nullable();     // [{key,visible}] ordered

            $table->timestamps();
        });

        TenantRls::enableFor('teacher_profiles');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
