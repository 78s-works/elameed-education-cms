<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student support tickets (M09, B24 / VD Item 11): create, list-own-only,
 * attachment linking, and tenant/ownership isolation.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(Tenant $tenant, TenantUserRole $role = TenantUserRole::Student): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_student_opens_a_ticket(): void
    {
        Sanctum::actingAs($this->member($this->tenant));

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/support/tickets', [
            'subject' => 'Cannot open lesson', 'body' => 'The player is stuck loading.', 'priority' => 'urgent',
        ])->assertStatus(201)
            ->assertJsonPath('data.subject', 'Cannot open lesson')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.status', 'open');
    }

    public function test_priority_defaults_to_normal(): void
    {
        Sanctum::actingAs($this->member($this->tenant));

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/support/tickets', [
            'subject' => 'Question', 'body' => 'A general question.',
        ])->assertStatus(201)->assertJsonPath('data.priority', 'normal');
    }

    public function test_ticket_carries_an_uploaded_attachment(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->member($this->tenant));
        $h = ['X-Tenant' => 'demo'];

        $attachmentUuid = $this->withHeaders($h)->postJson('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ])->assertStatus(201)->json('data.uuid');

        $this->withHeaders($h)->postJson('/api/v1/support/tickets', [
            'subject' => 'See screenshot', 'body' => 'Error attached.', 'attachment_ids' => [$attachmentUuid],
        ])->assertStatus(201)
            ->assertJsonPath('data.attachments.0.uuid', $attachmentUuid)
            ->assertJsonPath('data.attachments.0.kind', 'image');
    }

    public function test_index_lists_only_own_tickets_and_show_of_anothers_is_404(): void
    {
        $mine = $this->member($this->tenant);
        $other = $this->member($this->tenant);
        $h = ['X-Tenant' => 'demo'];

        // Another student's ticket in the same tenant.
        Sanctum::actingAs($other);
        $foreignUuid = $this->withHeaders($h)->postJson('/api/v1/support/tickets', [
            'subject' => 'Not yours', 'body' => 'private',
        ])->json('data.uuid');

        Sanctum::actingAs($mine);
        $this->withHeaders($h)->postJson('/api/v1/support/tickets', [
            'subject' => 'Mine', 'body' => 'hello',
        ])->assertStatus(201);

        // Index returns only the caller's ticket.
        $this->withHeaders($h)->getJson('/api/v1/support/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Mine');

        // Reading another student's ticket is not found.
        $this->withHeaders($h)->getJson("/api/v1/support/tickets/{$foreignUuid}")->assertStatus(404);
    }

    public function test_show_returns_the_thread_with_replies(): void
    {
        $student = $this->member($this->tenant);
        $staff = $this->member($this->tenant, TenantUserRole::Teacher);
        $h = ['X-Tenant' => 'demo'];

        Sanctum::actingAs($student);
        $uuid = $this->withHeaders($h)->postJson('/api/v1/support/tickets', [
            'subject' => 'Help', 'body' => 'I need help.',
        ])->json('data.uuid');

        // Seed a staff reply directly (staff reply endpoint is out of B24 scope).
        $ticket = SupportTicket::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        $reply = new TicketReply(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'On it.']);
        $reply->tenant_id = $this->tenant->id;
        $reply->save();

        $this->withHeaders($h)->getJson("/api/v1/support/tickets/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.replies.0.body', 'On it.')
            ->assertJsonPath('data.replies.0.author.name', $staff->name);
    }

    public function test_cross_tenant_ticket_is_404(): void
    {
        // A ticket in another tenant, owned by a foreign user.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreign = $this->member($other);
        $ticket = new SupportTicket(['user_id' => $foreign->id, 'subject' => 'x', 'body' => 'y']);
        $ticket->tenant_id = $other->id;
        $ticket->save();

        // A demo-tenant student cannot resolve it (BelongsToTenant scope).
        Sanctum::actingAs($this->member($this->tenant));
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson("/api/v1/support/tickets/{$ticket->uuid}")
            ->assertStatus(404);
    }
}
