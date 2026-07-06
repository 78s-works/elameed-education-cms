<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['pdf', 'file', 'link'])],
            'title' => ['nullable', 'string', 'max:255'],
            // A link needs a url; a pdf/file needs an uploaded file.
            'url' => ['nullable', 'url', 'max:2048', 'required_if:type,link'],
            'file' => ['nullable', 'file', 'max:20480', 'required_if:type,pdf', 'required_if:type,file'],
            'downloadable' => ['boolean'],
        ];
    }
}
