<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\RoleTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * A role template in the platform-admin console (M20).
 *
 * `copies_count` is the honest cost of a resync: how many academies would have
 * their copy of this role overwritten.
 *
 * @mixin RoleTemplate
 */
class RoleTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key->value,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            // The owner template is code-derived: the console shows the set but
            // refuses edits to it (see RoleTemplateController::update).
            'is_derived' => $this->key === \App\Modules\Identity\Enums\RoleTemplateKey::Teacher,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()->all()),
            'permissions_count' => $this->whenLoaded('permissions', fn () => $this->permissions->count()),
            'copies_count' => Role::query()->where('template_key', $this->key->value)->count(),
        ];
    }
}
