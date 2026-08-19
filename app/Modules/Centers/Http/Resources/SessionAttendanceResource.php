<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Catalog\Models\LessonAccessWindow;
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
            'lessons' => $this->lessonGates(),
            'access_expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $expiresAt === null ? null : max(0, $expiresAt->getTimestamp() - now()->getTimestamp()),
            'active' => $expiresAt === null || $expiresAt->isFuture(),
        ];
    }

    /**
     * Per-lesson gate rows: each of the session's lessons with its OWN window
     * expiry (each opens for its own availability_days). Unlimited lessons carry
     * no window and never expire.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lessonGates(): array
    {
        return ($this->centerSession?->lessons ?? collect())->map(function ($lesson): array {
            $windowed = (int) $lesson->availability_days > 0;
            $window = $windowed
                ? LessonAccessWindow::withoutGlobalScopes()
                    ->where('tenant_id', $this->tenant_id)
                    ->where('user_id', $this->user_id)
                    ->where('lesson_id', $lesson->id)
                    ->first()
                : null;

            return [
                'lesson_title' => $lesson->title,
                'availability_days' => (int) $lesson->availability_days,
                'expires_at' => $windowed ? $window?->expires_at?->toIso8601String() : null,
                'active' => $windowed ? ($window !== null && ! $window->isLocked()) : true,
            ];
        })->values()->all();
    }
}
