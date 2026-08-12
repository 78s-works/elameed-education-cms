<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\CenterIdCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CenterIdCode */
class CenterIdCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'grade' => $this->grade,
            'sequence' => $this->sequence,
            'center_id' => $this->center_id,
            'center_uuid' => $this->whenLoaded('center', fn () => $this->center->uuid),
            'academic_year_uuid' => $this->whenLoaded('academicYear', fn () => $this->academicYear->uuid),
            'status' => $this->status->value,
            'batch_id' => $this->batch_id,
            'generated_by' => $this->generated_by,
            'used_by' => $this->used_by,
            'used_at' => $this->used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
