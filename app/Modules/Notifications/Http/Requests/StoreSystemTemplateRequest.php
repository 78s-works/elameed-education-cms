<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Create/activate a system template for a (type, channel) (doc 10 §9.1).
 */
class StoreSystemTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', new Enum(NotificationChannel::class)],
        ];
    }
}
