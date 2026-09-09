<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notifications` — in-app + outbound (SMS/WhatsApp/email) messages
 * (03_Data_Model.md §3). P1 uses in_app + sms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel')->default('in_app'); // in_app|sms|whatsapp|email
            $table->string('type');                        // event key, e.g. purchase.completed
            $table->unsignedBigInteger('template_id')->nullable(); // P1.5
            $table->json('payload')->nullable();
            $table->string('status')->default('sent');     // pending|sent|failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'read_at']);
        });

        TenantRls::enableFor('notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
