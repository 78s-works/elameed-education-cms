<?php

namespace App\Modules\Wallet\Http\Requests;

use App\Modules\Wallet\Models\PaymentReceipt;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reviewer approves a manual top-up receipt (VD R10 / F4, D13-7). `corrected_amount_minor`
 * is optional: when the reviewer edits the figure the wallet is credited with the corrected
 * value instead of the student-submitted `amount_minor`. Omit it to approve as submitted.
 */
class ApproveReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route enforces role:teacher,assistant + permission:finance
    }

    public function rules(): array
    {
        return [
            'corrected_amount_minor' => ['nullable', 'integer', 'min:1', 'max:'.PaymentReceipt::MAX_AMOUNT_MINOR],
        ];
    }
}
