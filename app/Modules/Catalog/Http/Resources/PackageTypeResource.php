<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\PackageType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A content-package type (B27). `id` is the internal id used as `package_type_id`
 * when tagging a package; `uuid` is the public handle used to address the type in
 * its own CRUD routes (mirrors PackageResource: id for linking, uuid public).
 *
 * @mixin PackageType
 */
class PackageTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
