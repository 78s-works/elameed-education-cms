<?php

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'title' => $this->title,
            'url' => $this->url,
            'downloadable' => $this->downloadable,
            'duration_sec' => $this->duration_sec,
        ];
    }
}
