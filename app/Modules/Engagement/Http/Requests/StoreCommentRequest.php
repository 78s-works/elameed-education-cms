<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Support\Files\DocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a lesson comment/question or a reply (M09). May carry `document_ids`
 * (uuids from a prior POST /documents); ownership and the unattached state are
 * both enforced when linking, so a uuid lifted from another thread is ignored.
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
            ...DocumentRules::documentIds(),
        ];
    }
}
