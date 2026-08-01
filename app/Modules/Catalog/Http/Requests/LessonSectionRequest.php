<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use App\Support\Youtube;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a typed lesson section (media-only in the convention model). `type`
 * decides the payload: a `pdf` needs `media_asset_id`; a video (lecture_video /
 * quiz_solution / hw_solution) needs `media_asset_id` OR a `youtube_url`. Sections
 * no longer host exams — quizzes/homework link to the lesson directly.
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
            'youtube_url' => ['nullable', 'string', 'max:2048'],
            'pdf_kind' => ['nullable', new Enum(PdfKind::class)],
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

            $media = $this->input('media_asset_id');
            $youtube = $this->input('youtube_url');
            $hasYoutube = is_string($youtube) && trim($youtube) !== '';

            // A pdf strictly needs an uploaded asset.
            if ($type === LessonSectionType::Pdf && $media === null) {
                $validator->errors()->add('media_asset_id', 'A pdf section requires a media_asset_id.');
            }

            // A video section may be an uploaded asset OR a YouTube link.
            if ($type->isVideo() && $media === null && ! $hasYoutube) {
                $validator->errors()->add('media_asset_id', "A {$type->value} section requires a media_asset_id or a youtube_url.");
            }

            // youtube_url is only meaningful on a video section, and must be a real link.
            if ($hasYoutube) {
                if (! $type->isVideo()) {
                    $validator->errors()->add('youtube_url', 'youtube_url is only valid on a video section.');
                } elseif (! Youtube::isValid($youtube)) {
                    $validator->errors()->add('youtube_url', 'youtube_url must be a valid YouTube link.');
                }
            }

            if ($this->input('pdf_kind') !== null && $type !== LessonSectionType::Pdf) {
                $validator->errors()->add('pdf_kind', 'pdf_kind is only valid on a pdf section.');
            }
        });
    }
}
