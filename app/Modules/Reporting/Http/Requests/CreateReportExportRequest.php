<?php

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Enums\ExportFormat;
use App\Modules\Reporting\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a report-export request (EDU-021).
 *
 * `report` is restricted to the teacher-facing set: the platform report is the
 * admin console's, and letting a teacher name it here would have the job build
 * cross-tenant revenue into a file the teacher can download.
 *
 * `filters` are passed through to the report and stored on the row, so a file
 * found later still says what it covers. They are validated loosely on purpose —
 * each report reads the keys it understands and ignores the rest — but the
 * dangerous ones are pinned: an academic year must be an id, and free-text
 * search has a length ceiling.
 */
class CreateReportExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'report' => ['required', Rule::in(array_map(
                fn (ReportType $t) => $t->value,
                ReportType::teacherCases(),
            ))],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],

            // The ledger's own filter vocabulary — the same keys the sales
            // screen sends to /teacher/sales, so an export reproduces exactly
            // the slice the teacher is looking at. `status` and `method` arrive
            // as arrays from the screen's multi-selects.
            'filters' => ['sometimes', 'array'],
            'filters.date_from' => ['sometimes', 'nullable', 'date'],
            'filters.date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:filters.date_from'],
            // The ledger screen sends arrays (multi-select); the roster screen
            // sends a single membership status. Both are legitimate, so the
            // shape is not pinned — only the members are.
            'filters.status' => ['sometimes', 'nullable'],
            'filters.status.*' => ['string', 'max:32'],
            'filters.method' => ['sometimes', 'nullable'],
            'filters.method.*' => ['string', 'max:32'],
            'filters.student_id' => ['sometimes', 'nullable', 'integer'],
            'filters.item_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.q' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Resolved by the controller from the request's year context; a
            // client-supplied value is not trusted.
            'filters.academic_year_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'filters.academic_year_id.prohibited' => 'The academic year comes from the X-Academic-Year header, not the request body.',
        ];
    }
}
