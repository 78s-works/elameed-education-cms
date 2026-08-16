<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teacher_profiles` — per-tenant branding + landing + auth toggles. Squashed
 * create (folds hide_ranking, layout, locales/primary_locale, auth toggles,
 * custom_landing, favicon, and registration_verification_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->text('bio')->nullable();
            $table->json('contact')->nullable();
            $table->json('socials')->nullable();
            $table->json('landing_sections')->nullable();
            $table->json('locales')->nullable();
            $table->string('primary_locale', 8)->default('ar');
            $table->string('layout', 32)->default('classic');
            $table->boolean('hide_ranking')->default(false);
            $table->boolean('login_enabled')->default(true);
            $table->boolean('registration_enabled')->default(true);
            $table->string('registration_verification_mode', 16)->default('auto');
            $table->boolean('custom_landing_enabled')->default(false);
            $table->timestamps();

            $table->unique('tenant_id');
        });

        TenantRls::enableFor('teacher_profiles');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
