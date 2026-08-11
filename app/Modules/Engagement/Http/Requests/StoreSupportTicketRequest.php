<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a support ticket (M09, B24). May carry `attachment_ids` (uuids of
 * previously-uploaded attachments); ownership is enforced when linking.
 */
class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any active tenant member may open a ticket
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['string'],
        ];
    }
}
