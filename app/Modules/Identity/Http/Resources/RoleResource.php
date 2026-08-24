<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * A role as the academy panel sees it (M20). Bound by uuid, never by the
 * auto-increment id, so ids stay internal.
 *
 * `is_system` drives the lock in the UI, but the lock itself is enforced
 * server-side — a hidden button is not a control.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'template_key' => $this->template_key,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()->all()),
            'permissions_count' => $this->whenLoaded('permissions', fn () => $this->permissions->count()),
            'members_count' => $this->when(isset($this->users_count), fn () => (int) $this->users_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
