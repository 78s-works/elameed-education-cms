<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\UnitDependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UnitDependency
 */
class UnitDependencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'depends_on_unit_id' => $this->depends_on_unit_id,
            'depends_on_section_id' => $this->depends_on_section_id,
            'trigger' => $this->trigger?->value,
            'enforcement' => $this->enforcement?->value,
        ];
    }
}
