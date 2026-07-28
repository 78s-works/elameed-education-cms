<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationModule;
use App\Modules\Notifications\Enums\NotificationSeverity;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Update a notification type (doc 10 §9.1). `key` is immutable (it is the
 * identity other modules dispatch against); only module/severity/status change.
 */
class UpdateNotificationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module' => ['sometimes', new Enum(NotificationModule::class)],
            'severity' => ['sometimes', new Enum(NotificationSeverity::class)],
            'status' => ['sometimes', new Enum(NotificationTypeStatus::class)],
        ];
    }
}
