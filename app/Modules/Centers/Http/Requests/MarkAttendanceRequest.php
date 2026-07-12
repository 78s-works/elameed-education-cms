<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk-mark attendance for a center on a day: a list of student uuids, plus an
 * optional shared status/course/date.
 */
class MarkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'attended_on' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['present', 'absent'])],
            'course_id' => ['nullable', 'integer'],
            'students' => ['required', 'array', 'min:1', 'max:500'],
            'students.*' => ['string'], // student uuids
        ];
    }
}
