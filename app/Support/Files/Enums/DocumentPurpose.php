<?php

namespace App\Support\Files\Enums;

/**
 * What a stored file is FOR. This is the axis every other decision hangs off:
 * the disk it lands on, the mime/size rules it must pass, who may read it, and
 * which tab it shows up in. Adding a new kind of upload to the system means
 * adding a case here and an entry in config/documents.php — nothing else.
 */
enum DocumentPurpose: string
{
    case LessonAttachment = 'lesson_attachment';
    case CommentAttachment = 'comment_attachment';
    case TicketAttachment = 'ticket_attachment';
    case PaymentReceipt = 'payment_receipt';
    case AssignmentSubmission = 'assignment_submission';
    case AssignmentCorrected = 'assignment_corrected';
    case LandingImage = 'landing_image';
    case BrandingLogo = 'branding_logo';
    case BrandingFavicon = 'branding_favicon';
    case BrandingCover = 'branding_cover';
    case PackageCover = 'package_cover';
    case VideoSource = 'video_source';
    case VideoThumbnail = 'video_thumbnail';
    case InvoicePdf = 'invoice_pdf';
    case StudentImport = 'student_import';

    /** Config block for this purpose (visibility + mimes + max_kb). */
    public function rules(): array
    {
        return (array) config('documents.purposes.'.$this->value, []);
    }

    public function visibility(): DocumentVisibility
    {
        return DocumentVisibility::from($this->rules()['visibility'] ?? 'private');
    }

    public function maxKilobytes(): int
    {
        return (int) ($this->rules()['max_kb'] ?? 20480);
    }

    public function mimes(): string
    {
        return (string) ($this->rules()['mimes'] ?? '');
    }

    /**
     * Purposes a student owns and may manage from their own files tab. Anything
     * else a student can see (a teacher's corrected file, a lesson PDF) is
     * read-only to them — they didn't upload it.
     */
    public static function studentOwned(): array
    {
        return [
            self::AssignmentSubmission,
            self::PaymentReceipt,
            self::CommentAttachment,
            self::TicketAttachment,
        ];
    }

    /**
     * Purposes whose row is created by the system rather than by a person
     * clicking upload. They appear in the files tab but are not user-deletable.
     */
    public function isSystemGenerated(): bool
    {
        return in_array($this, [self::InvoicePdf, self::VideoThumbnail], true);
    }
}
