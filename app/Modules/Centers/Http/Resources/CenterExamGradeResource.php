<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\CenterExamGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CenterExamGrade */
class CenterExamGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'total_marks' => (float) $this->total_marks,
            'score' => (float) $this->score,
            'sat_on' => $this->sat_on?->toDateString(),
            'note' => $this->note,
            'center' => [
                'uuid' => $this->center?->uuid,
                'name' => $this->center?->name,
            ],
            'student' => [
                'uuid' => $this->student?->uuid,
                'name' => $this->student?->name,
                'phone' => $this->student?->phone,
            ],
            'entered_by' => $this->enteredBy?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
