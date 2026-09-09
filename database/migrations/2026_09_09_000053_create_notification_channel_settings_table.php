<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_channel_settings` — per-tenant channel config. Squashed create
 * (config folded as text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel');
            $table->text('config')->nullable();
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
