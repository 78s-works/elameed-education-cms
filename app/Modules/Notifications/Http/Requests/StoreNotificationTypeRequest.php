<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationModule;
use App\Modules\Notifications\Enums\NotificationSeverity;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Create a notification type (doc 10 §9.1). Central admin only. `key` is the
 * identity — dotted module.entity.event, unique across the catalog.
 */
class StoreNotificationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // central + auth:sanctum + admin gates
    }

    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:150',
                'regex:/^[a-z0-9]+(\.[a-z0-9_]+)+$/',
                Rule::unique('notification_types', 'key'),
            ],
            'module' => ['required', new Enum(NotificationModule::class)],
            'severity' => ['nullable', new Enum(NotificationSeverity::class)],
            'status' => ['nullable', new Enum(NotificationTypeStatus::class)],
        ];
    }
}
