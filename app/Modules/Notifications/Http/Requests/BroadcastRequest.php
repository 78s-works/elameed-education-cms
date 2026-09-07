<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared shape of a custom notification — used for both the cost preview and the
 * send, so the sender can never confirm one payload and submit another.
 *
 * Copy is bilingual and each half is optional, but at least one language must
 * carry a title AND a body: a message with a title and no text is not a message.
 */
class BroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant + role + permission gates on the route
    }

    /**
     * Accept `audience_ids` as JSON numbers as well as strings — a picker that
     * sends `[12, 13]` and one that sends `["12", "uuid…"]` mean the same thing.
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('audience_ids');

        if (is_array($ids)) {
            $this->merge([
                'audience_ids' => array_values(array_map(
                    static fn ($id): string => is_scalar($id) ? trim((string) $id) : '',
                    $ids,
                )),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'audience_type' => ['required', Rule::in($this->allowedAudiences())],
            // Numeric ids or uuids — AudienceResolver accepts both, because the
            // SPA's resources expose a uuid for some of these (academic years,
            // centers, students) and a numeric id for others (lessons).
            'audience_ids' => ['array', 'max:5000'],
            'audience_ids.*' => ['required', 'string', 'max:64'],

            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in($this->allowedChannels())],

            'title_ar' => ['nullable', 'string', 'max:150'],
            'body_ar' => ['nullable', 'string', 'max:2000'],
            'title_en' => ['nullable', 'string', 'max:150'],
            'body_en' => ['nullable', 'string', 'max:2000'],

            // Send now when absent. A past timestamp is rejected rather than
            // silently sent, so a mistyped date cannot fire immediately.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $audience = BroadcastAudience::tryFrom((string) $this->input('audience_type'));

            if ($audience?->requiresIds() && $this->input('audience_ids', []) === []) {
                $validator->errors()->add('audience_ids', 'Pick at least one target for this audience.');
            }

            $arabic = trim((string) $this->input('title_ar')) !== '' && trim((string) $this->input('body_ar')) !== '';
            $english = trim((string) $this->input('title_en')) !== '' && trim((string) $this->input('body_en')) !== '';

            if (! $arabic && ! $english) {
                $validator->errors()->add('body_ar', 'Write a title and a body in Arabic or in English.');
            }
        });
    }

    /**
     * Audiences this surface may address. The teacher surface is academy-scoped;
     * the platform-admin controller overrides this to `teachers`.
     *
     * @return list<string>
     */
    protected function allowedAudiences(): array
    {
        return array_values(array_map(
            static fn (BroadcastAudience $a): string => $a->value,
            array_filter(
                BroadcastAudience::cases(),
                static fn (BroadcastAudience $a): bool => $a->isTenantScoped(),
            ),
        ));
    }

    /** @return list<string> */
    protected function allowedChannels(): array
    {
        return array_values(array_map(
            static fn (NotificationChannel $c): string => $c->value,
            NotificationChannel::cases(),
        ));
    }
}
