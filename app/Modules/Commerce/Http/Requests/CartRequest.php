<?php

namespace App\Modules\Commerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cart shape for /checkout/quote and /checkout/order. Prices are resolved
 * server-side, so no price is accepted from the client.
 */
class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', Rule::in(['course', 'wallet_topup'])],
            'items.*.course' => ['required_if:items.*.type,course', 'string'],
            'items.*.amount_minor' => ['required_if:items.*.type,wallet_topup', 'integer', 'min:1'],
        ];
    }
}
