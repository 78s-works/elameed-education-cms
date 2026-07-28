<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ContentDependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentDependency
 */
class ContentDependencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'depends_on_section_id' => $this->depends_on_section_id,
            'trigger' => $this->trigger?->value,
            'enforcement' => $this->enforcement?->value,
        ];
    }
}
