<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload an attachment (M09, FR-M09-05): image, voice note, or file. Validated by
 * extension + size; the kind is derived server-side. Rate-limited at the route.
 */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any active member
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:20480', // 20 MB
                'mimes:jpg,jpeg,png,gif,webp,mp3,m4a,ogg,wav,webm,pdf,doc,docx',
            ],
            'duration_sec' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }
}
