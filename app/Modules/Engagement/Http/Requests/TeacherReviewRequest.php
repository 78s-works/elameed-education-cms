<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update rules for teacher-managed reviews. On create (`POST`) a
 * `course_id` (one of the teacher's own courses) is required; on update it is
 * prohibited (a review can't be moved between courses). Course ownership is
 * asserted in the controller against the tenant scope.
 */
class TeacherReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'course_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'rating' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'is_visible' => ['boolean'],
        ];
    }
}
