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
            // `course`/`bundle` retired (VD §7); `package` is the recursive content
            // grouping and `lesson` the standalone unit of sale that replaced them.
            'items.*.type' => ['required', Rule::in(['package', 'lesson', 'wallet_topup'])],
            'items.*.package' => ['required_if:items.*.type,package', 'string'],
            'items.*.lesson' => ['required_if:items.*.type,lesson', 'integer', 'min:1'],
            'items.*.amount_minor' => ['required_if:items.*.type,wallet_topup', 'integer', 'min:1'],
            // Optional promo code (M21); validated + priced server-side.
            'coupon' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
