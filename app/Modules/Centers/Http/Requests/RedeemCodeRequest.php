<?php

namespace App\Modules\Centers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authenticated student
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
        ];
    }
}
