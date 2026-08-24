<?php

namespace App\Modules\Reporting\Http\Requests;

use App\Models\User;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Enums\SalesMethod;
use App\Modules\Commerce\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the sales ledger. Dates are inclusive day bounds — `date_from` is
 * pushed to 00:00 and `date_to` to 23:59:59, so "today → today" is the whole day
 * and not an empty range.
 *
 * The student is addressed by uuid (never the internal id) so the client never
 * handles a sequential key; `student_id` is accepted as an alias for the same
 * uuid because that is the name the endpoint contract uses.
 */
class SalesLedgerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'item_type' => ['nullable', Rule::in([OrderItem::TYPE_LESSON, OrderItem::TYPE_PACKAGE, OrderItem::TYPE_BOOK])],
            'item_id' => ['nullable', 'integer', 'min:1', 'required_with:item_type'],
            'student_id' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', 'array'],
            'method.*' => [Rule::in(SalesMethod::values())],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ];
    }

    /**
     * Normalized filters for {@see \App\Modules\Reporting\Services\SalesLedgerQuery}.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $from = $this->input('date_from');
        $to = $this->input('date_to');

        return [
            'date_from' => $from === null ? null : \Carbon\CarbonImmutable::parse($from)->startOfDay(),
            'date_to' => $to === null ? null : \Carbon\CarbonImmutable::parse($to)->endOfDay(),
            'item_type' => $this->input('item_type'),
            'item_id' => $this->input('item_id'),
            'student_id' => $this->studentId(),
            'method' => array_filter((array) $this->input('method', [])),
            'status' => array_filter((array) $this->input('status', [])),
            'q' => (string) $this->input('q', ''),
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }

    public function pageNumber(): int
    {
        return (int) $this->input('page', 1);
    }

    /** Resolves the student uuid (or raw id) to the internal user id. */
    private function studentId(): ?int
    {
        $value = $this->input('student_id');

        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit((string) $value)) {
            return (int) $value;
        }

        // 0 when the uuid matches nobody: an unknown student filters everything
        // out, rather than silently widening to the whole ledger.
        return (int) (User::query()->where('uuid', $value)->value('id') ?? 0);
    }
}
