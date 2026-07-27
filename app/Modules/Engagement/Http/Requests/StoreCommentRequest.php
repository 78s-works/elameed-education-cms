<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a lesson comment/question or a reply (M09). May carry `attachment_ids`
 * (uuids of previously-uploaded attachments); ownership is enforced when linking.
 */
class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access checked in the controller (enrollment or staff)
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['string'],
        ];
    }
}
