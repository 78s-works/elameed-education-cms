<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assistant, as the teacher sees them (M18): the shared global identity keyed
 * by uuid + their per-academy membership status and delegated permissions.
 *
 * @mixin TenantUser
 */
class AssistantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->user?->uuid,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'email' => $this->user?->email,
            'status' => $this->status->value,
            'permissions' => $this->effectivePermissions(),
            // The academic years this assistant serves (M18 — year-scoped roster).
            'academic_year_ids' => $this->whenLoaded('academicYears', fn () => $this->academicYears->pluck('uuid')->all()),
            'academic_years' => $this->whenLoaded('academicYears', fn () => $this->academicYears
                ->map(fn ($y) => ['id' => $y->uuid, 'name' => $y->name])->values()->all()),
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
