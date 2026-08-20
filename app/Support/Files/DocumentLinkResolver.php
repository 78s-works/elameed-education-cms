<?php

namespace App\Support\Files;

use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * Answers "what is holding on to this file?" for both linking patterns.
 *
 * Pattern B rows say so themselves through `documentable_*`. Pattern A rows are
 * referenced from elsewhere, so the lookup runs backwards — from the purpose to
 * the one table that can point at it. Doing it by purpose keeps this to a single
 * indexed query instead of probing nine tables.
 *
 * Used by three callers: the policy (to authorize by the owner's rules), the
 * files tab (to show what a file belongs to), and delete (to refuse, with the
 * link named, until the teacher unlinks it).
 */
class DocumentLinkResolver
{
    public function resolve(Document $document): ?DocumentLink
    {
        return $document->isAttached()
            ? $this->fromDocumentable($document)
            : $this->fromOwningTable($document);
    }

    public function isLinked(Document $document): bool
    {
        return $this->resolve($document) !== null;
    }

    /**
     * Break the link so the file can be deleted. Pattern B clears the columns on
     * the document; Pattern A nulls the FK on whatever points at it.
     */
    public function unlink(Document $document): void
    {
        if ($document->isAttached()) {
            $document->forceFill([
                'documentable_type' => null,
                'documentable_id' => null,
            ])->save();

            return;
        }

        $link = $this->fromOwningTable($document);

        if ($link === null) {
            return;
        }

        $column = $this->columnFor($document->purpose, $link->owner);
        $link->owner->forceFill([$column => null])->save();
    }

    // — Pattern B —

    private function fromDocumentable(Document $document): ?DocumentLink
    {
        $owner = $document->documentable;

        if ($owner === null) {
            return null; // the owner was deleted out from under it
        }

        return new DocumentLink(
            owner: $owner,
            type: $this->typeName($owner),
            label: $this->labelFor($owner),
            uuid: $this->uuidOf($owner),
        );
    }

    // — Pattern A —

    private function fromOwningTable(Document $document): ?DocumentLink
    {
        $owner = match ($document->purpose) {
            DocumentPurpose::LessonAttachment => LessonSection::query()
                ->where('document_id', $document->getKey())->first(),
            DocumentPurpose::PaymentReceipt => PaymentReceipt::query()
                ->where('document_id', $document->getKey())->first(),
            DocumentPurpose::BrandingLogo => TeacherProfile::query()
                ->where('logo_document_id', $document->getKey())->first(),
            DocumentPurpose::BrandingFavicon => TeacherProfile::query()
                ->where('favicon_document_id', $document->getKey())->first(),
            DocumentPurpose::BrandingCover => TeacherProfile::query()
                ->where('cover_document_id', $document->getKey())->first(),
            DocumentPurpose::PackageCover => Package::query()
                ->where('cover_document_id', $document->getKey())->first(),
            DocumentPurpose::InvoicePdf => Invoice::query()
                ->where('pdf_document_id', $document->getKey())->first(),
            DocumentPurpose::VideoSource => MediaAsset::query()
                ->where('source_document_id', $document->getKey())->first(),
            DocumentPurpose::VideoThumbnail => MediaAsset::query()
                ->where('thumbnail_document_id', $document->getKey())->first(),
            default => null,
        };

        return $owner === null ? null : new DocumentLink(
            owner: $owner,
            type: $this->typeName($owner),
            label: $this->labelFor($owner),
            uuid: $this->uuidOf($owner),
        );
    }

    /** Which FK on the owner points back at a document of this purpose. */
    private function columnFor(DocumentPurpose $purpose, Model $owner): string
    {
        return match ($purpose) {
            DocumentPurpose::BrandingLogo => 'logo_document_id',
            DocumentPurpose::BrandingFavicon => 'favicon_document_id',
            DocumentPurpose::BrandingCover => 'cover_document_id',
            DocumentPurpose::PackageCover => 'cover_document_id',
            DocumentPurpose::InvoicePdf => 'pdf_document_id',
            DocumentPurpose::VideoSource => 'source_document_id',
            DocumentPurpose::VideoThumbnail => 'thumbnail_document_id',
            default => 'document_id',
        };
    }

    // — presentation —

    private function typeName(Model $owner): string
    {
        return match (true) {
            $owner instanceof LessonSection => 'lesson_section',
            $owner instanceof Lesson => 'lesson',
            $owner instanceof Comment => 'comment',
            $owner instanceof SupportTicket => 'support_ticket',
            $owner instanceof TicketReply => 'ticket_reply',
            $owner instanceof ExamAttempt => 'exam_attempt',
            $owner instanceof PaymentReceipt => 'payment_receipt',
            $owner instanceof Invoice => 'invoice',
            $owner instanceof TeacherProfile => 'branding',
            $owner instanceof Package => 'package',
            $owner instanceof MediaAsset => 'video',
            default => class_basename($owner),
        };
    }

    /** A human label for the files tab — never an id the teacher can't read. */
    private function labelFor(Model $owner): string
    {
        return match (true) {
            $owner instanceof LessonSection => trim(sprintf(
                '%s — %s',
                (string) ($owner->lesson?->title ?? __('Lesson')),
                (string) ($owner->title ?: __('Part')),
            ), ' —'),
            $owner instanceof Lesson => (string) $owner->title,
            $owner instanceof Comment => __('Comment on :lesson', ['lesson' => $owner->lesson?->title ?? '—']),
            $owner instanceof SupportTicket => (string) ($owner->subject ?? __('Support ticket')),
            $owner instanceof TicketReply => __('Reply on :subject', ['subject' => $owner->ticket?->subject ?? '—']),
            $owner instanceof ExamAttempt => __('Attempt on :exam', ['exam' => $owner->exam?->title ?? '—']),
            $owner instanceof PaymentReceipt => __('Payment receipt'),
            $owner instanceof Invoice => __('Invoice :number', ['number' => $owner->number ?? $owner->getKey()]),
            $owner instanceof TeacherProfile => __('Academy branding'),
            $owner instanceof Package => (string) $owner->name,
            $owner instanceof MediaAsset => (string) ($owner->title ?? __('Video')),
            default => class_basename($owner),
        };
    }

    private function uuidOf(Model $owner): ?string
    {
        return isset($owner->uuid) ? (string) $owner->uuid : null;
    }
}
