<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies within a support-ticket thread (M09, B24 / VD Item 11). A row is a
 * message from the ticket's student or from staff; attachments reuse the
 * polymorphic `attachments` table (attachable_type = TicketReply).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_replies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
        });

        TenantRls::enableFor('ticket_replies');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
    }
};
