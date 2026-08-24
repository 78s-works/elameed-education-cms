<?php

namespace App\Modules\Commerce\Http\Requests;

use App\Modules\Commerce\Models\Refund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Refund one order. `amount_minor` is optional — omitted means "refund whatever
 * is still refundable", which is the whole order for a first, full refund.
 */
class RefundOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', Rule::in([Refund::TO_WALLET, Refund::TO_OFFLINE])],
        ];
    }
}
