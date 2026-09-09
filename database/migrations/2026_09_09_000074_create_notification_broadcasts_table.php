<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_broadcasts` — custom (human-written) notifications: a teacher
 * writing to a slice of his students or his assistants, and the central admin
 * writing to every teacher. Automatic notifications keep using the type/template
 * catalog; this table stores the one-off copy plus the audience it was aimed at.
 *
 * `tenant_id` is NULL for a platform-admin broadcast (audience `teachers`) and
 * set for an academy broadcast. Like `notification_templates`, the table is NOT
 * RLS-forced for exactly that reason — it deliberately mixes platform rows with
 * tenant rows, and every query scopes itself explicitly.
 *
 * The estimate columns freeze what the sender was shown at confirm time
 * (recipients, SMS segments, cost) so a later audience change cannot rewrite
 * history; `stats` holds the engine's per-channel result after the send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('audience_type');              // BroadcastAudience
            $table->json('audience_ids')->nullable();     // lesson/package/year/center/user ids
            $table->json('channels');                     // ['database','sms',...]

            $table->string('title_ar')->nullable();
            $table->text('body_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('body_en')->nullable();

            $table->string('status')->default('scheduled'); // BroadcastStatus
            $table->timestamp('scheduled_at')->nullable();  // null = send immediately
            $table->timestamp('sent_at')->nullable();

            // Frozen confirmation estimate.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sms_recipient_count')->default(0);
            $table->unsignedInteger('sms_segments')->default(0);
            $table->unsignedInteger('sms_cost_minor')->default(0);
            $table->string('currency', 3)->default('EGP');

            $table->json('stats')->nullable();              // engine summary after sending
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};
