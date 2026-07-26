<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST/PUT /teacher/meta. `key` is unique per (tenant, group) —
 * the DB has the matching composite unique index; this rule surfaces a friendly
 * 422 instead of a driver error and ignores the current row on update.
 */
class TeacherMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:teacher middleware guards the route.
    }

    /**
     * Default `group` to "general" before validation so the unique scope and the
     * stored row agree (the column default only applies when the key is absent).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('group') || $this->input('group') === null || $this->input('group') === '') {
            $this->merge(['group' => 'general']);
        }
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $group = (string) $this->input('group', 'general');

        return [
            'group' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'key' => [
                'required',
                'string',
                'max:191',
                'regex:/^[A-Za-z0-9_.:-]+$/',
                Rule::unique('teacher_meta', 'key')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('group', $group))
                    ->ignore($this->route('meta')?->id),
            ],
            'value' => ['nullable', 'string', 'max:65535'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.unique' => 'A meta entry with this key already exists in this group.',
        ];
    }
}
