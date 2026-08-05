<?php

namespace App\Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reviewer rejects a manual top-up receipt (VD R10). A reason is required — it is
 * stamped on the receipt and shown back to the student.
 */
class RejectReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route enforces role:teacher,assistant + permission:finance
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
