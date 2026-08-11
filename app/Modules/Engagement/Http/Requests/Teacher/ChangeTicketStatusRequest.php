<?php

namespace App\Modules\Engagement\Http\Requests\Teacher;

use App\Modules\Engagement\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff changes a ticket's lifecycle status (B25 / VD Item 11) — one of the
 * TicketStatus cases (open | in_progress | closed). RBAC is on the route
 * (`permission:support`).
 */
class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ];
    }
}
