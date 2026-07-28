<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\LessonAvailabilityRequest;
use App\Modules\Catalog\Models\Lesson;
use Illuminate\Http\JsonResponse;

/**
 * /teacher/lessons/{lesson}/availability ("Lesson Availability & Extension
 * Requests"). Reads/sets the per-lesson time-box config: window length,
 * extension allowance, and extension length. availability_days = null disables
 * the window (unlimited access).
 */
class LessonAvailabilityController
{
    public function show(Lesson $lesson): JsonResponse
    {
        return response()->json(['data' => $this->payload($lesson)]);
    }

    public function update(LessonAvailabilityRequest $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validated();

        $attrs = ['availability_days' => $data['availability_days'] ?? null];
        if (array_key_exists('max_extensions', $data)) {
            $attrs['max_extensions'] = $data['max_extensions'] ?? 0;
        }
        if (array_key_exists('extension_hours', $data)) {
            $attrs['extension_hours'] = $data['extension_hours'] ?? 24;
        }

        $lesson->update($attrs);

        return response()->json(['data' => $this->payload($lesson->refresh())]);
    }

    private function payload(Lesson $lesson): array
    {
        return [
            'lesson_id' => $lesson->id,
            'availability_days' => $lesson->availability_days,
            'max_extensions' => (int) $lesson->max_extensions,
            'extension_hours' => (int) $lesson->extension_hours,
        ];
    }
}
