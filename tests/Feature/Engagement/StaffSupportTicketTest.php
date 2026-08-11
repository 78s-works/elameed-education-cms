<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff side of support tickets (M09, B25 / VD Item 11): teacher/assistant list +
 * filter + reply + status transition, the `support` permission gate, and M10
 * notifications on new ticket (to staff) / staff reply (to student).
 */
class StaffSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h = ['X-Tenant' => 'demo'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    /** @param  list<string>  $permissions */
    private function member(TenantUserRole $role = TenantUserRole::Student, array $permissions = []): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'permissions' => $permissions, 'joined_at' => now(),
        ]);

        return $user;
    }

    /** A student ticket created directly (bypasses the notify-on-create path). */
    private function ticket(User $owner, string $subject = 'Help', string $status = 'open', string $priority = 'normal'): SupportTicket
    {
        $ticket = new SupportTicket([
            'user_id' => $owner->id, 'subject' => $subject, 'body' => 'body', 'status' => $status, 'priority' => $priority,
        ]);
        $ticket->tenant_id = $this->tenant->id;
        $ticket->save();

        return $ticket;
    }

    public function test_teacher_lists_and_filters_tickets(): void
    {
        $student = $this->member();
        $this->ticket($student, 'A', 'open', 'urgent');
        $this->ticket($student, 'B', 'closed', 'normal');
        $this->ticket($student, 'C', 'open', 'normal');

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        // Unfiltered: all three.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets')
            ->assertOk()->assertJsonCount(3, 'data');

        // Filter by status.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets?status=open')
            ->assertOk()->assertJsonCount(2, 'data');

        // Filter by priority.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets?priority=urgent')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'A');
    }

    public function test_staff_reply_appears_in_thread_and_notifies_student(): void
    {
        $this->seed(NotificationCatalogSeeder::class);
        $student = $this->member();
        $ticket = $this->ticket($student);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders($this->h)->postJson("/api/v1/teacher/support/tickets/{$ticket->uuid}/replies", [
            'body' => 'We are looking into it.',
        ])->assertStatus(201)->assertJsonPath('data.body', 'We are looking into it.');

        $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $ticket->id, 'body' => 'We are looking into it.']);

        // Student (ticket owner) got an in-app notification for `support.ticket.replied`.
        $typeId = NotificationType::where('key', 'support.ticket.replied')->value('id');
        $this->assertTrue(
            NotificationMessage::withoutGlobalScopes()->where('user_id', $student->id)->exists(),
            'ticket owner should receive a reply notification',
        );
        $this->assertTrue(
            NotificationEvent::withoutGlobalScopes()
                ->where('notification_type_id', $typeId)
                ->where('entity_type', 'support_ticket')->where('entity_id', $ticket->id)->exists(),
        );
    }

    public function test_status_transitions_open_in_progress_closed(): void
    {
        $ticket = $this->ticket($this->member());
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        foreach (['in_progress', 'closed', 'open'] as $status) {
            $this->withHeaders($this->h)->patchJson("/api/v1/teacher/support/tickets/{$ticket->uuid}/status", [
                'status' => $status,
            ])->assertOk()->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id, 'status' => 'open']);

        // Unknown status is rejected.
        $this->withHeaders($this->h)->patchJson("/api/v1/teacher/support/tickets/{$ticket->uuid}/status", [
            'status' => 'archived',
        ])->assertStatus(422);
    }

    public function test_permission_gate_blocks_assistant_without_support_and_students(): void
    {
        $ticket = $this->ticket($this->member());

        // Assistant granted an unrelated permission → 403.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets')->assertStatus(403);

        // Plain student → 403 (role gate).
        Sanctum::actingAs($this->member());
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets')->assertStatus(403);

        // Assistant WITH support permission → allowed.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['support']));
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/support/tickets')->assertOk();

        // ...and can reply.
        $this->withHeaders($this->h)->postJson("/api/v1/teacher/support/tickets/{$ticket->uuid}/replies", [
            'body' => 'Handled by assistant.',
        ])->assertStatus(201);
    }

    public function test_new_ticket_notifies_only_support_staff(): void
    {
        $this->seed(NotificationCatalogSeeder::class);

        $teacher = $this->member(TenantUserRole::Teacher);              // implicit support
        $supportAssistant = $this->member(TenantUserRole::Assistant, ['support']);
        $otherAssistant = $this->member(TenantUserRole::Assistant, ['students']); // no support
        $bystander = $this->member();                                   // another student

        // Student opens a ticket via the real endpoint (fires notify-on-create).
        $student = $this->member();
        Sanctum::actingAs($student);
        $uuid = $this->withHeaders($this->h)->postJson('/api/v1/support/tickets', [
            'subject' => 'Broken video', 'body' => 'It will not play.',
        ])->assertStatus(201)->json('data.uuid');

        $ticketId = SupportTicket::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $typeId = NotificationType::where('key', 'support.ticket.created')->value('id');

        $this->assertTrue(
            NotificationEvent::withoutGlobalScopes()
                ->where('notification_type_id', $typeId)
                ->where('entity_id', $ticketId)->exists(),
        );

        $notified = fn (User $u): bool => NotificationMessage::withoutGlobalScopes()->where('user_id', $u->id)->exists();
        $this->assertTrue($notified($teacher), 'teacher should be notified');
        $this->assertTrue($notified($supportAssistant), 'support assistant should be notified');
        $this->assertFalse($notified($otherAssistant), 'non-support assistant must not be notified');
        $this->assertFalse($notified($bystander), 'other students must not be notified');
        $this->assertFalse($notified($student), 'the author must not be notified');
    }
}
