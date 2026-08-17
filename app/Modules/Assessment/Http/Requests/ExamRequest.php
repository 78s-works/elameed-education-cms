<?php

namespace App\Modules\Assessment\Http\Requests;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a teacher exam. `type` (one of the ExamTypes) fixes the required link:
 * lesson_quiz/homework need a `lesson_id`; free_exam links to nothing. `lesson_id`
 * is auto-filled/validated by the controller — never trusted from the client.
 * depends_on_exam_id is retired (no exam→exam gating); `courses`/units retired (VD §7).
 */
class ExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $creating = $this->isMethod('POST');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(array_column(ExamType::cases(), 'value'))],

            // Link required by type. required_if only fires when `type` is present in
            // the body, so a partial update that omits type keeps the existing link.
            'lesson_id' => [
                'nullable',
                'required_if:type,'.ExamType::LessonQuiz->value.','.ExamType::Homework->value,
                Rule::exists('lessons', 'id')->where('tenant_id', $tenantId),
            ],

            'pass_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'duration_min' => ['nullable', 'integer', 'min:1'],
            'max_time_extensions' => ['nullable', 'integer', 'min:0'],
            'attempts_allowed' => ['nullable', 'integer', 'min:0'], // 0 = unlimited
            'question_order' => ['nullable', Rule::in(['fixed', 'random'])],
            'scoring' => ['nullable', Rule::in(['best', 'last', 'first'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'result_visibility' => ['nullable', Rule::in(['immediate', 'after_close', 'manual'])],
            'show_answers' => ['boolean'],
            'mode' => ['nullable', Rule::in(['standard', 'bubble_sheet'])],
            'is_published' => ['boolean'],
        ];
    }
}
