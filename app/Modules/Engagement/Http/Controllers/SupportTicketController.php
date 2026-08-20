<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Models\User;
use App\Modules\Engagement\Enums\TicketPriority;
use App\Modules\Engagement\Enums\TicketStatus;
use App\Modules\Engagement\Http\Requests\StoreSupportTicketRequest;
use App\Modules\Engagement\Http\Resources\SupportTicketResource;
use App\Modules\Engagement\Models\SupportTicket;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use App\Modules\Engagement\Support\TicketRecipients;
use App\Modules\Notifications\Services\Engine\NotificationEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Student support tickets (M09, B24 / VD Item 11). A student opens a ticket
 * (subject + message + optional attachments + priority), lists their own
 * tickets, and reads a single thread with its replies. Tenant isolation is the
 * BelongsToTenant global scope; ownership is enforced per action (a ticket is
 * only ever the caller's own). Staff-side triage/reply is out of B24 scope.
 */
class SupportTicketController
{
    public function __construct(private readonly DocumentService $documents) {}

    /** The caller's own tickets, newest first, with attachments + reply count. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = SupportTicket::query()
            ->ownedBy($request->user()->getKey())
            ->with('documents')
            ->withCount('replies')
            ->latest('id')
            ->paginate(20);

        return SupportTicketResource::collection($tickets);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $user = $request->user();

        $ticket = new SupportTicket([
            'user_id' => $user->getKey(),
            'subject' => $request->validated('subject'),
            'body' => $request->validated('body'),
            'priority' => $request->validated('priority', TicketPriority::Normal->value),
            'status' => TicketStatus::Open->value,
        ]);
        $ticket->save();

        $this->linkAttachments($ticket, $request, $user);
        $this->notifyStaff($ticket, $user);

        return (new SupportTicketResource($ticket->load('documents')))->response()->setStatusCode(201);
    }

    /** A single ticket thread — the opening message plus its replies. */
    public function show(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        abort_unless($ticket->user_id === $request->user()->getKey(), 404);

        $ticket->load([
            'documents',
            'replies' => fn ($q) => $q->with('user', 'documents')->orderBy('id'),
        ]);

        return new SupportTicketResource($ticket);
    }

    /**
     * Notify staff (teacher + assistants holding the `support` permission) that a
     * new ticket landed, via the M10 engine (key `support.ticket.created`). A
     * no-op if the type is not seeded/ready or there are no staff recipients.
     */
    private function notifyStaff(SupportTicket $ticket, User $author): void
    {
        $recipients = TicketRecipients::staffFor((int) $ticket->tenant_id);
        if ($recipients === []) {
            return;
        }

        app(NotificationEngineService::class)->dispatch(
            notificationKey: 'support.ticket.created',
            tenantId: (int) $ticket->tenant_id,
            recipientUserIds: $recipients,
            renderVariables: [
                'student.name' => (string) $author->name,
                'ticket.subject' => (string) $ticket->subject,
            ],
            triggeredByUserId: $author->getKey(),
            entityType: 'support_ticket',
            entityId: $ticket->getKey(),
            auditPayload: ['ticket_uuid' => $ticket->uuid],
        );
    }

    /**
     * Link the caller's own, still-unattached uploads to the ticket. Scoped to
     * the uploader's unattached ticket documents, so a uuid from someone else's
     * thread cannot be re-parented here.
     */
    private function linkAttachments(SupportTicket $ticket, StoreSupportTicketRequest $request, User $user): void
    {
        $uuids = (array) ($request->validated('document_ids') ?? []);

        if ($uuids === []) {
            return;
        }

        $documents = Document::query()
            ->whereIn('uuid', $uuids)
            ->ownedBy($user->getKey())
            ->ofPurpose(DocumentPurpose::TicketAttachment)
            ->unattached()
            ->get();

        foreach ($documents as $document) {
            $this->documents->attachTo($document, $ticket);
        }
    }
}
