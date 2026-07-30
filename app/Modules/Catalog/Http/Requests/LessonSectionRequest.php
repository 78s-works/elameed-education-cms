<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a typed lesson section. `type` decides which payload is required:
 * media types (lecture_video/assignment_video/pdf) need `media_asset_id`; exam
 * types (assignment/quiz) need `exam_id`; only `pdf` accepts a `pdf_kind`.
 */
class LessonSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(LessonSectionType::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'media_asset_id' => ['nullable', 'integer', 'min:1'],
            'exam_id' => ['nullable', 'integer', 'min:1'],
            'pdf_kind' => ['nullable', new Enum(PdfKind::class)],
            'assignment_kind' => ['nullable', new Enum(AssignmentKind::class)],
            'is_required' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = LessonSectionType::tryFrom((string) $this->input('type'));
            if ($type === null) {
                return;
            }

            if ($type->usesMedia() && $this->input('media_asset_id') === null) {
                $validator->errors()->add('media_asset_id', "A {$type->value} section requires a media_asset_id.");
            }

            if ($type->usesExam() && $this->input('exam_id') === null) {
                $validator->errors()->add('exam_id', "A {$type->value} section requires an exam_id.");
            }

            if ($this->input('pdf_kind') !== null && $type !== LessonSectionType::Pdf) {
                $validator->errors()->add('pdf_kind', 'pdf_kind is only valid on a pdf section.');
            }

            if ($this->input('assignment_kind') !== null && $type !== LessonSectionType::Assignment) {
                $validator->errors()->add('assignment_kind', 'assignment_kind is only valid on an assignment section.');
            }
        });
    }
}
