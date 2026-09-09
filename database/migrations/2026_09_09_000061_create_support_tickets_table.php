<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets (M09, B24 / VD Item 11). A student opens a ticket to
 * teacher/assistant with a subject + opening message and an optional urgency;
 * `status` tracks the open|in_progress|closed lifecycle and `assigned_to` names
 * the staff owner once triaged. Attachments reuse the polymorphic `attachments`
 * table (attachable_type = SupportTicket); replies live in `ticket_replies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject');
            $table->text('body');
            $table->string('priority')->default('normal'); // normal | urgent
            $table->string('status')->default('open');      // open | in_progress | closed

            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });

        TenantRls::enableFor('support_tickets');
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
