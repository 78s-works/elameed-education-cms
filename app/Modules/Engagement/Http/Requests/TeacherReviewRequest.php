<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Models\Review;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update rules for teacher-managed reviews. On create (`POST`) a content
 * target (`target_type` = lesson|package + numeric `target_id`, one of the
 * teacher's own) is required; on update it is prohibited (a review can't be moved
 * between targets). Tenant scope is asserted via the `exists` rule (VD §7 —
 * `courses` retired).
 */
class TeacherReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'target_type' => [$creating ? 'required' : 'prohibited', Rule::in(Review::targetTypes())],
            'target_id' => [
                $creating ? 'required' : 'prohibited', 'integer',
                Rule::exists(
                    $this->input('target_type') === Review::TARGET_PACKAGE ? 'packages' : 'lessons',
                    'id',
                )->where('tenant_id', $tenantId),
            ],
            'rating' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'is_visible' => ['boolean'],
        ];
    }
}
