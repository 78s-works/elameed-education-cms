<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student on a teacher's roster (GET /teacher/students). Wraps the student's
 * tenant_user membership + the joined user, plus an `enrolled_courses` count the
 * controller pre-computes (avoids an N+1).
 *
 * @mixin TenantUser
 */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->user?->uuid,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'email' => $this->user?->email,
            'status' => $this->status->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'enrolled_courses' => (int) ($this->enrolled_courses ?? 0),
        ];
    }
}
