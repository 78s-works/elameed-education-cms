<?php

namespace App\Modules\Engagement\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Engagement\Enums\TicketPriority;
use App\Modules\Engagement\Enums\TicketStatus;
use App\Modules\Engagement\Http\Requests\Teacher\ChangeTicketStatusRequest;
use App\Modules\Engagement\Http\Requests\Teacher\StoreTicketReplyRequest;
use App\Modules\Engagement\Http\Resources\SupportTicketResource;
use App\Modules\Engagement\Http\Resources\TicketReplyResource;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Staff side of support tickets (M09, B25 / VD Item 11). Teacher — or an
 * assistant granted the `support` permission (M18) — lists every ticket in the
 * academy, filters by status/priority, reads a thread, replies, and moves the
 * lifecycle (open | in_progress | closed). Tenant isolation is the
 * BelongsToTenant global scope; unlike the student surface there is NO owner
 * check — staff see the whole tenant. The student surface lives in the parent
 * {@see \App\Modules\Engagement\Http\Controllers\SupportTicketController}.
 */
class SupportTicketController
{
    public function __construct(private readonly TenantContext $context) {}

    /** Every ticket in the tenant, newest first; filter by ?status= and ?priority=. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
        ]);

        $tickets = SupportTicket::query()
            ->with(['user:id,uuid,name', 'attachments'])
            ->withCount('replies')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->latest('id')
            ->paginate(20);

        return SupportTicketResource::collection($tickets);
    }

    /** One ticket thread — opening message + attachments + ordered replies. */
    public function show(SupportTicket $ticket): SupportTicketResource
    {
        $ticket->load([
            'attachments',
            'replies' => fn ($q) => $q->with('user', 'attachments')->orderBy('id'),
        ]);

        return new SupportTicketResource($ticket);
    }

    /** Staff posts a reply into the thread and the ticket owner is notified. */
    public function reply(StoreTicketReplyRequest $request, SupportTicket $ticket): JsonResponse
    {
        $staff = $request->user();

        $reply = new TicketReply([
            'ticket_id' => $ticket->getKey(),
            'user_id' => $staff->getKey(),
            'body' => $request->validated('body'),
        ]);
        $reply->save();

        $this->linkAttachments($reply, $request->validated('attachment_ids') ?? [], $staff);
        $this->notifyStudent($ticket, $staff);

        return (new TicketReplyResource($reply->load('user', 'attachments')))->response()->setStatusCode(201);
    }

    /** Move the ticket's lifecycle status (open | in_progress | closed). */
    public function updateStatus(ChangeTicketStatusRequest $request, SupportTicket $ticket): SupportTicketResource
    {
        $ticket->status = $request->validated('status');
        $ticket->save();

        app(AuditLogger::class)->log('support_ticket.status_changed', [
            'ticket_id' => $ticket->getKey(),
            'status' => $ticket->status->value,
        ], $this->context->tenantOrFail()->getKey(), 'support_ticket', $ticket->getKey());

        return new SupportTicketResource($ticket->fresh());
    }

    /**
     * Notify the ticket owner that staff replied, via the M10 engine (key
     * `support.ticket.replied`). No-op if the type is not seeded/ready.
     */
    private function notifyStudent(SupportTicket $ticket, User $staff): void
    {
        app(NotificationEngineService::class)->dispatch(
            notificationKey: 'support.ticket.replied',
            tenantId: (int) $ticket->tenant_id,
            recipientUserIds: [(int) $ticket->user_id],
            renderVariables: ['ticket.subject' => (string) $ticket->subject],
            triggeredByUserId: $staff->getKey(),
            entityType: 'support_ticket',
            entityId: $ticket->getKey(),
            auditPayload: ['ticket_uuid' => $ticket->uuid],
        );
    }

    /**
     * Link the caller's own, still-unattached uploads to the reply.
     *
     * @param  array<int, string>  $uuids
     */
    private function linkAttachments(TicketReply $reply, array $uuids, User $staff): void
    {
        if ($uuids === []) {
            return;
        }

        Attachment::query()
            ->whereIn('uuid', $uuids)
            ->where('uploaded_by', $staff->getKey())
            ->whereNull('attachable_id')
            ->update([
                'attachable_type' => $reply->getMorphClass(),
                'attachable_id' => $reply->getKey(),
            ]);
    }
}
