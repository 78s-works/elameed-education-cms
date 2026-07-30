<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk student-history import: a single `.xlsx` or `.csv` upload. Row-level
 * matching/validation happens in StudentImportService (per-row results), so this
 * only guards the file itself.
 */
class StudentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Validate by the user-assigned extension (deterministic across the
                // office/zip/text MIME variants xlsx & csv can present as).
                'extensions:xlsx,csv,txt',
                'max:'.(int) config('identity.import_max_kb', 10240),
            ],
        ];
    }
}
