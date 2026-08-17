<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check a list of center students in for one lesson part. Center + section are
 * resolved (and channel-validated) in the controller; students are uuids, and
 * ineligible ones (not a center student, not a member) are skipped, not rejected.
 */
class CheckinAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:centers
    }

    public function rules(): array
    {
        return [
            'center_uuid' => ['required', 'string'],
            'lesson_section_id' => ['required', 'integer'],
            'students' => ['required', 'array', 'min:1', 'max:500'],
            'students.*' => ['string'], // student uuids
        ];
    }
}
