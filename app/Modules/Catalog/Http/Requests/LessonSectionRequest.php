<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Assessment\Enums\ExamPassMode;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Enums\GateRule;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use App\Modules\Catalog\Models\Lesson;
use App\Support\Youtube;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a lesson part (VD change set §8.3). `type` drives the payload:
 *
 *   video    → { media_asset_id }
 *   homework → { pass_mode, pass_value, total_marks?, gate_rule, max_tries? }
 *   quiz     → same as homework, plus an optional duration_min cap
 *
 * Delivery method (upload vs bubble sheet) and grading mode are NO LONGER part
 * inputs — they live on the backing exam and are edited from the exam settings
 * page (BUGS.docx). `delivery` on the part row is derived by the controller from
 * the exam's mode.
 *
 * Cross rules (server-authoritative): part.access_mode ⊆ lesson.access_mode;
 * pass_mode=marks ⇒ total_marks present and pass_value ≤ total_marks. The
 * exam-degree fields are split out via examAttributes() — the controller uses them
 * to build the backing exam.
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
            'type' => ['required', Rule::in(LessonSectionType::authoringValues())],
            'name' => ['nullable', 'string', 'max:255'],
            'access_mode' => ['required', Rule::enum(AccessMode::class)],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // video — an uploaded asset OR a YouTube link (one is required, see
            // assertVideoSource()). youtube_url is validated as a real YouTube URL.
            // pdf — a required uploaded file (a MediaAsset of type pdf).
            'media_asset_id' => ['nullable', 'integer', 'min:1', 'required_if:type,pdf'],
            'pdf_kind' => ['nullable', Rule::enum(PdfKind::class), 'required_if:type,pdf'],
            'youtube_url' => ['nullable', 'string', 'max:2048', function ($attr, $value, $fail): void {
                if ($value !== null && $value !== '' && ! Youtube::isValid($value)) {
                    $fail('The :attribute must be a valid YouTube link.');
                }
            }],

            // homework / quiz — backed by an exam. Delivery method (upload vs bubble
            // sheet) and grading mode now live on the exam and are set from the exam
            // settings page, NOT here (BUGS.docx — "move these options to exam page").
            'pass_mode' => ['nullable', Rule::enum(ExamPassMode::class), 'required_if:type,homework,quiz'],
            'pass_value' => ['nullable', 'numeric', 'min:0', 'required_if:type,homework,quiz'],
            'total_marks' => ['nullable', 'numeric', 'min:0', 'required_if:pass_mode,marks'],
            'gate_rule' => ['nullable', Rule::enum(GateRule::class), 'required_if:type,homework,quiz'],
            'max_tries' => ['nullable', 'integer', 'min:1'],
            'duration_min' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = LessonSectionType::tryFrom((string) $this->input('type'));
            if ($type === null) {
                return; // the enum rule already produced the error
            }

            $this->assertWithinLessonCeiling($validator);

            if ($type === LessonSectionType::Video) {
                $this->assertVideoSource($validator);
            }

            if ($type->backsExam()) {
                $this->assertExamRules($validator);
            }
        });
    }

    /** A video part needs exactly one source: an uploaded asset OR a YouTube link. */
    private function assertVideoSource(Validator $validator): void
    {
        $hasMedia = $this->filled('media_asset_id');
        $hasYoutube = $this->filled('youtube_url');

        if (! $hasMedia && ! $hasYoutube) {
            $validator->errors()->add('media_asset_id', 'A video part needs an uploaded video or a YouTube link.');
        }
    }

    /** part.access_mode ⊆ lesson.access_mode (LP-5). */
    private function assertWithinLessonCeiling(Validator $validator): void
    {
        $lesson = $this->route('lesson');
        $part = AccessMode::tryFrom((string) $this->input('access_mode'));

        if ($lesson instanceof Lesson && $part !== null && ! $part->isSubsetOf($lesson->access_mode)) {
            $validator->errors()->add(
                'access_mode',
                sprintf("The part's access_mode (%s) must be within the lesson's access_mode (%s).", $part->value, $lesson->access_mode->value),
            );
        }
    }

    private function assertExamRules(Validator $validator): void
    {
        $passMode = ExamPassMode::tryFrom((string) $this->input('pass_mode'));

        // Absolute-marks threshold needs a total, and cannot exceed it (LP-11).
        if ($passMode === ExamPassMode::Marks) {
            $total = $this->input('total_marks');
            $passValue = $this->input('pass_value');

            if ($total === null) {
                $validator->errors()->add('total_marks', 'total_marks is required when pass_mode is marks.');
            } elseif ($passValue !== null && (float) $passValue > (float) $total) {
                $validator->errors()->add('pass_value', 'pass_value cannot exceed total_marks.');
            }
        }

        // Percentage threshold stays within 0–100.
        if ($passMode === ExamPassMode::Percent) {
            $passValue = $this->input('pass_value');

            if ($passValue !== null && ((float) $passValue < 0 || (float) $passValue > 100)) {
                $validator->errors()->add('pass_value', 'pass_value must be between 0 and 100 for percent mode.');
            }
        }
    }

    /**
     * The columns that live on lesson_sections (public `name` → `title`).
     *
     * @return array<string, mixed>
     */
    public function sectionAttributes(): array
    {
        $data = $this->validated();
        // `delivery` is NOT client-set anymore — the controller derives it from the
        // backing exam's mode (single source of truth), so it's absent here.
        $keys = ['type', 'access_mode', 'gate_rule', 'max_tries', 'sort_order', 'media_asset_id', 'youtube_url', 'pdf_kind', 'is_required'];

        $attrs = array_intersect_key($data, array_flip($keys));

        if (array_key_exists('name', $data)) {
            $attrs['title'] = $data['name'];
        }

        return $attrs;
    }

    /**
     * The degree/grading fields that live on the backing exam.
     *
     * @return array<string, mixed>
     */
    public function examAttributes(): array
    {
        // grading_mode is set from the exam settings page, not the part dialog.
        $keys = ['pass_mode', 'pass_value', 'total_marks', 'duration_min'];

        return array_intersect_key($this->validated(), array_flip($keys));
    }
}
