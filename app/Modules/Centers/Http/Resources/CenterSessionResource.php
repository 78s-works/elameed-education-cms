<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\CenterSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CenterSession */
class CenterSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'session_at' => $this->session_at?->toIso8601String(),
            'center' => [
                'uuid' => $this->center?->uuid,
                'name' => $this->center?->name,
            ],
            'lessons' => $this->lessons->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
            ])->values(),
            // Present only when the controller withCount()s it — used to gate delete.
            'attendance_count' => $this->whenCounted('attendance'),
        ];
    }
}
