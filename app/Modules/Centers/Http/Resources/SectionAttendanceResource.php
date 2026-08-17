<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class SectionAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $section = $this->lessonSection;
        $lesson = $section?->lesson;
        $expiresAt = $this->access_expires_at;

        return [
            'id' => $this->id,
            'student' => [
                'uuid' => $this->student?->uuid,
                'name' => $this->student?->name,
                'phone' => $this->student?->phone,
            ],
            'section' => [
                'id' => $section?->id,
                'title' => $section?->title,
                'access_mode' => $section?->access_mode?->value,
            ],
            'lesson' => [
                'id' => $lesson?->id,
                'title' => $lesson?->title,
            ],
            'attended_on' => $this->attended_on?->toDateString(),
            'access_expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $expiresAt === null ? null : max(0, $expiresAt->getTimestamp() - now()->getTimestamp()),
            'active' => $expiresAt === null || $expiresAt->isFuture(),
        ];
    }
}
