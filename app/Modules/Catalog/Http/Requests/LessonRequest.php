<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\VideoSource;
use App\Support\Youtube;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Standalone lesson authoring (VD change set §8.3). The public field is `name`
 * (mapped onto the `title` column); the academic year comes from the
 * X-Academic-Year context, never the body (LP-10). On update, `name` /
 * `access_mode` are optional; the access_mode-narrowing re-check against existing
 * parts happens in the controller via LessonAccessModeGuard.
 *
 * The lesson-level video-source toggle (youtube_url / active_video_source) is
 * retained here — the protected upload asset is still assigned by the Media step,
 * not this request.
 */
class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'access_mode' => [$required, Rule::enum(AccessMode::class)],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_purchasable' => ['boolean'],
            'availability_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'description' => ['nullable', 'string'],
            'is_free_preview' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'duration_sec' => ['nullable', 'integer', 'min:0'],
            'max_views' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', Rule::enum(ContentVisibility::class)],
            'publish_at' => ['nullable', 'date'],
            'youtube_url' => ['nullable', 'string', 'max:2048', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && ! Youtube::isValid($value)) {
                    $fail('The :attribute must be a valid YouTube link.');
                }
            }],
            'active_video_source' => ['nullable', Rule::enum(VideoSource::class)],
        ];
    }

    /**
     * Activating YouTube requires an effective YouTube link (in this request or
     * already stored on the lesson). Selecting `upload` is always allowed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('active_video_source') !== VideoSource::Youtube->value) {
                return;
            }

            $existing = $this->route('lesson')?->youtube_url;
            $effective = $this->has('youtube_url') ? $this->input('youtube_url') : $existing;

            if (! Youtube::isValid($effective)) {
                $validator->errors()->add(
                    'active_video_source',
                    'Cannot activate the YouTube source without a valid youtube_url.',
                );
            }
        });
    }

    /**
     * Validated data shaped for the model: the public `name` maps onto the
     * `title` column.
     *
     * @return array<string, mixed>
     */
    public function lessonAttributes(): array
    {
        $data = $this->validated();

        if (array_key_exists('name', $data)) {
            $data['title'] = $data['name'];
            unset($data['name']);
        }

        return $data;
    }
}
