<?php

namespace App\Modules\Media\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a request to start (or replace) a remote video upload. The bytes go
 * straight to the Media Host; here we only validate metadata + enforce the
 * configured max size.
 */
class StartRemoteUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:teacher middleware
    }

    public function rules(): array
    {
        return [
            'lesson_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'content_type' => ['required', 'string', 'max:100', 'in:video/mp4,video/quicktime,video/webm,video/x-matroska'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $max = config('media.host.max_upload_bytes');
            if ($max !== null && (int) $this->input('size_bytes') > (int) $max) {
                $v->errors()->add('size_bytes', "The file exceeds the maximum allowed size ({$max} bytes).");
            }
        });
    }
}
