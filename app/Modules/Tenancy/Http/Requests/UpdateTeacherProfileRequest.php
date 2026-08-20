<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Support\Files\Enums\DocumentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized by the role:teacher middleware
    }

    /** The uuid must belong to this tenant and be an image we accepted as branding. */
    private function documentExists(DocumentPurpose ...$purposes): Exists
    {
        return Rule::exists('documents', 'uuid')
            ->whereIn('purpose', array_map(fn (DocumentPurpose $p) => $p->value, $purposes));
    }

    public function rules(): array
    {
        $hex = 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

        return [
            // Branding images are uploaded first (POST /teacher/landing/media),
            // then referenced by uuid — so the profile can never point at a file
            // the platform does not know about.
            'logo_document_uuid' => ['nullable', 'uuid', $this->documentExists(DocumentPurpose::LandingImage, DocumentPurpose::BrandingLogo)],
            'favicon_document_uuid' => ['nullable', 'uuid', $this->documentExists(DocumentPurpose::LandingImage, DocumentPurpose::BrandingFavicon)],
            'cover_document_uuid' => ['nullable', 'uuid', $this->documentExists(DocumentPurpose::LandingImage, DocumentPurpose::BrandingCover)],
            'primary_color' => ['nullable', 'string', $hex],
            'secondary_color' => ['nullable', 'string', $hex],
            'bio' => ['nullable', 'string', 'max:2000'],
            'registration_verification_mode' => ['sometimes', 'string', 'in:auto,otp'],

            'contact' => ['nullable', 'array'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.whatsapp' => ['nullable', 'string', 'max:32'],
            'contact.address' => ['nullable', 'string', 'max:500'],

            'socials' => ['nullable', 'array'],
            'socials.*' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
