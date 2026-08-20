<?php

namespace App\Modules\Engagement\Http\Requests\Teacher;

use App\Support\Files\DocumentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff reply to a support ticket (B25 / VD Item 11). May carry `document_ids`
 * (uuids of the caller's own previously-uploaded attachments); ownership is
 * enforced when linking. RBAC is handled by the route (`permission:support`).
 */
class StoreTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            ...DocumentRules::documentIds(),
        ];
    }
}
