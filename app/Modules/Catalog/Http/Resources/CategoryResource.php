<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\CourseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CourseCategory
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'grade' => $this->grade,
            'subject' => $this->subject,
            'level' => $this->level,
            'section' => $this->section,
            'sort_order' => $this->sort_order,
        ];
    }
}
