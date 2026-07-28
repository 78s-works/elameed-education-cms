<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Teacher overrides one channel of a notification (doc 10 §9.2). Materializes a
 * tenant copy on first edit; `is_active = false` hard-disables the channel for
 * this tenant (no fallback to the system template).
 */
class TeacherOverrideChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant + role + permission gates on the route
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', new Enum(NotificationChannel::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
