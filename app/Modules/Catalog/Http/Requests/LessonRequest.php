<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\VideoSource;
use App\Support\Youtube;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'duration_sec' => ['nullable', 'integer', 'min:0'],
            'max_views' => ['nullable', 'integer', 'min:1'],
            'is_free_preview' => ['boolean'],
            'visibility' => ['nullable', new Enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
            // Video sources: the protected upload (video_asset_id) is assigned by the
            // Media step, not here. The YouTube link + which source is active are set here.
            'youtube_url' => ['nullable', 'string', 'max:2048', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && ! Youtube::isValid($value)) {
                    $fail('The :attribute must be a valid YouTube link.');
                }
            }],
            'active_video_source' => ['nullable', new Enum(VideoSource::class)],
        ];
    }

    /**
     * Guard the toggle: activating YouTube requires an effective YouTube link
     * (the one in this request, or one already stored on the lesson). Selecting
     * `upload` is always allowed — it's the default; playback simply reports "no
     * ready video" until a video is uploaded.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('active_video_source') !== VideoSource::Youtube->value) {
                return;
            }

            $existing = $this->route('lesson')?->youtube_url;
            $effective = $this->has('youtube_url') ? $this->input('youtube_url') : $existing;

            if (! Youtube::isValid($effective)) {
                $validator->errors()->add(
                    'active_video_source',
                    'Cannot activate the YouTube source without a valid youtube_url.'
                );
            }
        });
    }
}
