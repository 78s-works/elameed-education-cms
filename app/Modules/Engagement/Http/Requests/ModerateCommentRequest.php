<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Enums\CommentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher moderation of a comment (M09, FR-M09-03/04): change status and/or
 * hide it from students.
 */
class ModerateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(CommentStatus::class)],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }
}
