<?php

namespace App\Support\Files\Http\Requests;

use App\Support\Files\DocumentRules;
use App\Support\Files\Enums\DocumentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The generic upload. The client names what the file is for, and the mime/size
 * rules follow from config — so the limits live in one place instead of being
 * re-typed into every FormRequest that happens to accept a file.
 *
 * Only the purposes a person legitimately uploads by hand are accepted here.
 * System-generated files (invoice PDFs, video posters) are written by the code
 * that produces them, and purposes with their own dedicated endpoint (a video
 * source, a student import) keep those endpoints' stricter flows.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', DocumentRules::purposeIn(self::allowedPurposes())],
            'file' => $this->fileRules(),
        ];
    }

    public function purpose(): DocumentPurpose
    {
        return DocumentPurpose::from($this->string('purpose')->value());
    }

    /**
     * The file's rules depend on the purpose, which is itself user input — so a
     * bad purpose is reported as such rather than tripping over a missing config
     * block while building the file rule.
     */
    private function fileRules(): array
    {
        $purpose = DocumentPurpose::tryFrom((string) $this->input('purpose'));

        return $purpose === null
            ? ['required', 'file']
            : DocumentRules::for($purpose);
    }

    /** @return array<int, DocumentPurpose> */
    public static function allowedPurposes(): array
    {
        return [
            DocumentPurpose::CommentAttachment,
            DocumentPurpose::TicketAttachment,
            DocumentPurpose::PaymentReceipt,
            DocumentPurpose::AssignmentSubmission,
            DocumentPurpose::AssignmentCorrected,
            DocumentPurpose::LessonAttachment,
            DocumentPurpose::LandingImage,
            DocumentPurpose::BrandingLogo,
            DocumentPurpose::BrandingFavicon,
            DocumentPurpose::BrandingCover,
            DocumentPurpose::PackageCover,
        ];
    }
}
