<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class SessionAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->centerSession;
        $expiresAt = $this->access_expires_at;

        return [
            'id' => $this->id,
            'student' => [
                'uuid' => $this->student?->uuid,
                'name' => $this->student?->name,
                'phone' => $this->student?->phone,
            ],
            'session' => [
                'id' => $session?->id,
                'name' => $session?->name,
                'session_at' => $session?->session_at?->toIso8601String(),
            ],
            'attended_on' => $this->attended_on?->toDateString(),
            'attended_at' => $this->created_at?->toIso8601String(),
            'access_expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $expiresAt === null ? null : max(0, $expiresAt->getTimestamp() - now()->getTimestamp()),
            'active' => $expiresAt === null || $expiresAt->isFuture(),
        ];
    }
}
