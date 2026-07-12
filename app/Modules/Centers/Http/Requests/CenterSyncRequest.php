<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Offline center-app sync: a batch of events, each with a client `external_ref`
 * for idempotency. Per-event specifics are resolved server-side (results returned
 * per item), so validation here is deliberately loose.
 */
class CenterSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.kind' => ['required', Rule::in(['attendance', 'redeem'])],
            'events.*.external_ref' => ['required', 'string', 'max:100'],
            'events.*.center_uuid' => ['nullable', 'string'],
            'events.*.student_uuid' => ['nullable', 'string'],
            'events.*.student_phone' => ['nullable', 'string'],
            'events.*.code' => ['nullable', 'string', 'max:40'],
            'events.*.attended_on' => ['nullable', 'date'],
            'events.*.status' => ['nullable', Rule::in(['present', 'absent'])],
        ];
    }
}
