<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teacher manually adjusts a student's wallet (a gift/top-up or a correction).
 * Posted to the ledger as a balanced adjustment — never a raw balance edit.
 */
class WalletAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
