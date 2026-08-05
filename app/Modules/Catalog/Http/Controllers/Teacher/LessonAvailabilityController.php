<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Catalog\Http\Requests\LessonAvailabilityRequest;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /teacher/lessons/{lesson}/availability ("Lesson Availability & Extension
 * Requests"). Reads/sets the per-lesson time-box config: window length,
 * extension allowance, and extension length. availability_days = null disables
 * the window (unlimited access). `reopen` lets staff open the lesson for one
 * student for a custom number of hours (doc 11 R4).
 */
class LessonAvailabilityController
{
    public function __construct(
        private readonly LessonAvailabilityService $availability,
        private readonly TenantContext $context,
    ) {}

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
        if (array_key_exists('self_reopen_limit', $data)) {
            $attrs['self_reopen_limit'] = $data['self_reopen_limit'] ?? 0;
        }

        $lesson->update($attrs);

        return response()->json(['data' => $this->payload($lesson->refresh())]);
    }

    /** Open this lesson for one student for a custom number of hours (doc 11 R4). */
    public function reopen(Request $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validate([
            'student' => ['required', 'string'], // student uuid
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $tenantId = (int) $this->context->tenantOrFail()->getKey();

        $student = User::query()->where('uuid', $data['student'])->first();
        abort_if($student === null, 404, 'Student not found.');

        $isMember = TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();
        abort_unless($isMember, 404, 'Student not found in this academy.');

        $window = $this->availability->reopen($tenantId, (int) $student->getKey(), $lesson, (int) $data['hours']);

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'student' => $student->uuid,
            'expires_at' => $window->expires_at?->toIso8601String(),
            'locked' => $window->isLocked(),
        ]]);
    }

    private function payload(Lesson $lesson): array
    {
        return [
            'lesson_id' => $lesson->id,
            'availability_days' => $lesson->availability_days,
            'max_extensions' => (int) $lesson->max_extensions,
            'extension_hours' => (int) $lesson->extension_hours,
            'self_reopen_limit' => (int) $lesson->self_reopen_limit,
        ];
    }
}
