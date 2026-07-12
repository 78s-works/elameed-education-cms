<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'center_id' => $this->center_id,
            'student' => [
                'uuid' => $this->student?->uuid,
                'name' => $this->student?->name,
                'phone' => $this->student?->phone,
            ],
            'course_id' => $this->course_id,
            'attended_on' => $this->attended_on?->toDateString(),
            'status' => $this->status,
            'source' => $this->source,
        ];
    }
}
