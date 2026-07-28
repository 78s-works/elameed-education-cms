<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engine — per-tenant channel kill-switch + sender config (doc 10
 * §4, §7). Absence of a row means the channel is allowed; a row with
 * `is_active = false` skips the whole channel for the tenant silently. `config`
 * holds per-tenant sms/mailer sender credentials. Tenant-owned, so RLS-forced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'channel'], 'notif_channel_setting_unique');
        });

        TenantRls::enableFor('notification_channel_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_settings');
    }
};
