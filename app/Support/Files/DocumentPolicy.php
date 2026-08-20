<?php

namespace App\Support\Files;

use App\Models\User;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;

/**
 * Who may read a stored file.
 *
 * Until this class existed, lesson PDFs and comment attachments were served from
 * the public disk with no check at all — a link was the only credential needed
 * for paid material. Authorization keys off the document's purpose, and for the
 * content purposes it defers to the same enrollment and gating rules the rest of
 * the app already uses, so a file is never more reachable than the thing it
 * belongs to.
 *
 * Tenant isolation is not re-implemented here: the tenant scope plus Postgres RLS
 * mean a document from another academy is already invisible.
 */
class DocumentPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
        private readonly DocumentLinkResolver $links,
    ) {}

    public function view(User $user, Document $document): bool
    {
        if ($document->isPublic()) {
            return true;
        }

        if ($user->isPlatformAdmin() || $this->isTeacher($user)) {
            return true;
        }

        // The uploader can always read their own file back.
        if ((int) $document->owner_id === (int) $user->getKey()) {
            return true;
        }

        return match ($document->purpose) {
            DocumentPurpose::LessonAttachment => $this->canReadLessonFile($user, $document),
            DocumentPurpose::CommentAttachment => $this->canReadComment($user, $document),
            DocumentPurpose::TicketAttachment => $this->canReadTicket($user, $document),
            DocumentPurpose::PaymentReceipt => $this->hasPermission($user, Permission::Finance),
            DocumentPurpose::AssignmentSubmission,
            DocumentPurpose::AssignmentCorrected => $this->canReadAttemptFile($user, $document),
            DocumentPurpose::InvoicePdf => $this->canReadInvoice($user, $document),
            // A student never touches the source MP4 — they get gated HLS instead.
            DocumentPurpose::VideoSource => $this->isAssistant($user),
            DocumentPurpose::StudentImport => $this->hasPermission($user, Permission::Students),
            default => false,
        };
    }

    /** Managing the academy library is a teacher surface, delegatable to assistants. */
    public function manage(User $user, Document $document): bool
    {
        return $this->isTeacher($user) || $this->hasPermission($user, Permission::Files);
    }

    /**
     * A student may delete a file they uploaded themselves, and only of a kind
     * they own — never a teacher's corrected return, even though they can read it.
     */
    public function deleteAsStudent(User $user, Document $document): bool
    {
        return (int) $document->owner_id === (int) $user->getKey()
            && in_array($document->purpose, DocumentPurpose::studentOwned(), true);
    }

    // — per-purpose rules —

    /**
     * A lesson file is exactly as reachable as its lesson. Pattern A (a PDF part)
     * resolves through the section; Pattern B (the materials list) hangs off the
     * lesson directly.
     */
    private function canReadLessonFile(User $user, Document $document): bool
    {
        $lesson = $this->lessonFor($document);

        if ($lesson === null) {
            return false; // unlinked file — only its uploader and staff, handled above
        }

        return $this->enrollments->hasLessonAccess(
            (int) $this->context->tenantId(),
            (int) $user->getKey(),
            $lesson,
        );
    }

    private function canReadComment(User $user, Document $document): bool
    {
        $owner = $document->documentable;

        if (! $owner instanceof Comment) {
            return false;
        }

        // Attachments on a comment are readable by anyone who can open the lesson
        // the comment lives on — the same audience that can read the comment text.
        return $owner->lesson !== null && $this->enrollments->hasLessonAccess(
            (int) $this->context->tenantId(),
            (int) $user->getKey(),
            $owner->lesson,
        );
    }

    private function canReadTicket(User $user, Document $document): bool
    {
        $owner = $document->documentable;

        $ticket = match (true) {
            $owner instanceof SupportTicket => $owner,
            $owner instanceof TicketReply => $owner->ticket,
            default => null,
        };

        if ($ticket === null) {
            return false;
        }

        return (int) $ticket->user_id === (int) $user->getKey()
            || $this->hasPermission($user, Permission::Support);
    }

    private function canReadAttemptFile(User $user, Document $document): bool
    {
        $attempt = $document->documentable;

        if (! $attempt instanceof ExamAttempt) {
            return false;
        }

        return (int) $attempt->user_id === (int) $user->getKey()
            || $this->hasPermission($user, Permission::Homework);
    }

    private function canReadInvoice(User $user, Document $document): bool
    {
        $link = $this->links->resolve($document);

        if (! $link?->owner instanceof Invoice) {
            return false;
        }

        return (int) $link->owner->user_id === (int) $user->getKey()
            || $this->hasPermission($user, Permission::Finance);
    }

    // — helpers —

    private function lessonFor(Document $document): ?Lesson
    {
        $owner = $document->documentable ?? $this->links->resolve($document)?->owner;

        return match (true) {
            $owner instanceof Lesson => $owner,
            $owner instanceof LessonSection => $owner->lesson,
            default => null,
        };
    }

    private function isTeacher(User $user): bool
    {
        return $this->roleOf($user) === TenantUserRole::Teacher;
    }

    private function isAssistant(User $user): bool
    {
        return in_array($this->roleOf($user), [TenantUserRole::Teacher, TenantUserRole::Assistant], true);
    }

    private function roleOf(User $user): ?TenantUserRole
    {
        $tenant = $this->context->tenant();

        return $tenant === null ? null : $user->membershipFor($tenant)?->role;
    }

    private function hasPermission(User $user, Permission $permission): bool
    {
        $tenant = $this->context->tenant();
        $membership = $tenant === null ? null : $user->membershipFor($tenant);

        return $membership !== null
            && $membership->isActive()
            && $membership->hasPermission($permission->value);
    }
}
