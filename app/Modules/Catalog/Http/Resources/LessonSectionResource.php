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
 * stamps each section with its computed unlock state (ContentUnlockService — only
 * solution videos are ever locked). Teacher listings omit it.
 */
class LessonSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // A locked solution video must NOT leak its protected media. Enforcement
        // lives here so a client can't reach the asset by ignoring the `locked`
        // display flag. PDF sections carry no gated asset (never locked).
        $gated = $this->isContentGated();

        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'media_asset_id' => $gated ? null : $this->media_asset_id,
            'youtube_url' => $gated ? null : $this->youtube_url,
            'pdf_kind' => $this->pdf_kind?->value,
            'is_required' => (bool) $this->is_required,
            'locked' => $this->whenNotNull($this->getAttribute('locked')),
            'media' => $this->when(
                ! $gated,
                fn () => $this->whenLoaded('mediaAsset', fn () => $this->mediaAsset ? new MediaAssetResource($this->mediaAsset) : null),
            ),
        ];
    }

    /**
     * True when this section is locked AND carries a gated (video) asset. PDF
     * sections have no protected asset, so they are never gated.
     */
    private function isContentGated(): bool
    {
        return $this->getAttribute('locked') === true
            && $this->pdf_kind === null;
    }
}
