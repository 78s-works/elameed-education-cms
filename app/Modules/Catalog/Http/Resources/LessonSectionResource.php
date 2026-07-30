<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LessonSection
 *
 * `locked` is present only on the student-facing listing, where the controller
 * stamps each section with its computed unlock state (ContentUnlockService).
 * Teacher listings omit it.
 */
class LessonSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'media_asset_id' => $this->media_asset_id,
            'exam_id' => $this->exam_id,
            'pdf_kind' => $this->pdf_kind?->value,
            'assignment_kind' => $this->assignment_kind?->value,
            'is_required' => (bool) $this->is_required,
            'locked' => $this->whenNotNull($this->getAttribute('locked')),
            'media' => $this->whenLoaded('mediaAsset', fn () => $this->mediaAsset ? new MediaAssetResource($this->mediaAsset) : null),
            'dependencies' => ContentDependencyResource::collection($this->whenLoaded('dependencies')),
        ];
    }
}
