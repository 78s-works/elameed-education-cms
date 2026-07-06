<?php

namespace App\Modules\Assessment\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'lesson_id' => ['nullable', Rule::exists('lessons', 'id')->where('tenant_id', $tenantId)],
            'type' => ['nullable', Rule::in(['exam', 'assignment'])],
            'pass_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'duration_min' => ['nullable', 'integer', 'min:1'],
            'attempts_allowed' => ['nullable', 'integer', 'min:0'], // 0 = unlimited
            'question_order' => ['nullable', Rule::in(['fixed', 'random'])],
            'scoring' => ['nullable', Rule::in(['best', 'last', 'first'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'result_visibility' => ['nullable', Rule::in(['immediate', 'after_close', 'manual'])],
            'show_answers' => ['boolean'],
            'depends_on_exam_id' => ['nullable', Rule::exists('exams', 'id')->where('tenant_id', $tenantId)],
            'mode' => ['nullable', Rule::in(['standard', 'bubble_sheet'])],
            'is_published' => ['boolean'],
        ];
    }
}
